<?php

declare(strict_types=1);

/**
 * Decode an incoming WebSocket message from the player's buffer.
 *
 * @param Player $player The player whose buffer is to be processed.
 * @param int &$bytes_consumed Output parameter for the number of bytes consumed from the buffer.
 * @return string|WebSocketDecodeError The decoded payload or an error enum.
 */
function ws_decode(Player $player, int &$bytes_consumed): string | WebSocketDecodeError {
    $data = $player->getSocketBuffer();
    $bytes_consumed = 0;

    $fragmented_message = $player->getFragmentedMessage();

    $len = strlen($data);
    if ($len < 2) return WebSocketDecodeError::InsufficientData;

    $header = unpack("Cfirst/Csecond", $data);
    $fin = ($header["first"] & 0x80) === 0x80;
    $opcode = $header["first"] & 0x0F;
    $masked = ($header["second"] & 0x80) === 0x80;
    $payload_len_indicator = $header["second"] & 0x7F;

    $current_offset = 2;

    $actual_payload_len = $payload_len_indicator;

    if ($payload_len_indicator === 126) {
        if ($len < $current_offset + 2) return WebSocketDecodeError::InsufficientData;
        $actual_payload_len = unpack("n", substr($data, $current_offset, 2))[1];
        $current_offset += 2;
    } elseif ($payload_len_indicator === 127) {
        if ($len < $current_offset + 8) return WebSocketDecodeError::InsufficientData;
        $actual_payload_len = unpack("J", substr($data, $current_offset, 8))[1];
        $current_offset += 8;
    }

    if (!$masked) {
        $bytes_consumed = $current_offset;
        return WebSocketDecodeError::UnmaskedFrame;
    }

    $mask_key_len = 4;
    if ($len < $current_offset + $mask_key_len) return WebSocketDecodeError::InsufficientData;

    $mask_key = substr($data, $current_offset, $mask_key_len);
    $current_offset += $mask_key_len;

    if ($len < $current_offset + $actual_payload_len) return WebSocketDecodeError::InsufficientData;

    $payload_data = substr($data, $current_offset, $actual_payload_len);
    $bytes_consumed = $current_offset + $actual_payload_len;

    $decoded_payload = "";
    for ($i = 0; $i < $actual_payload_len; $i++) {
        $decoded_payload .= $payload_data[$i] ^ $mask_key[$i % 4];
    }

    if ($opcode === 0x8) return WebSocketDecodeError::ClientDisconnect;

    if (!$fin) {
        // Start of a fragmented message
        if ($opcode !== 0x0) {
            if ($fragmented_message !== "") {
                fprintf(STDERR, "Warning: New fragmented message (opcode %x) started for player %s before previous completed. Old one discarded.\n", $opcode, $player->getPeerName());
                $player->setFragmentedMessage("");
                $player->setFragmentedOpcode(0);
            }
            $player->setFragmentedOpcode($opcode);
            $player->setFragmentedMessage($decoded_payload);
        } else {
            if ($fragmented_message === "") return WebSocketDecodeError::ContinuationFrameOutOfOrder;
            $player->setFragmentedMessage($fragmented_message . $decoded_payload);
        }

        return WebSocketDecodeError::FragmentedMessage;
    } else {
        if ($opcode === 0x0) {
            // Continuation frame
            if ($fragmented_message === "") return WebSocketDecodeError::ContinuationFrameOutOfOrder;

            $final_message = $fragmented_message . $decoded_payload;
            $message_opcode = $player->getFragmentedOpcode();
            $player->setFragmentedMessage("");
            $player->setFragmentedOpcode(0);

            if ($message_opcode === 0x1 || $message_opcode === 0x2) {
                return $final_message;
            } else {
                return WebSocketDecodeError::UnsupportedOpcode;
            }
        } else {
            // Non-fragmented message
            if ($fragmented_message !== "") {
                fprintf(STDERR, "Warning: New non-fragmented message (opcode %x) received for player %s while fragmented one in progress. Fragmented discarded.\n", $opcode, $player->getPeerName());
                $player->setFragmentedMessage("");
                $player->setFragmentedOpcode(0);
            }

            if ($opcode === 0x1 || $opcode === 0x2) {
                // Text or binary frame
                return $decoded_payload;
            } elseif ($opcode === 0x9) {
                // Ping
                printf("Info: Received Ping from %s\n", $player->getPeerName());
                return WebSocketDecodeError::UnsupportedOpcode;
            } elseif ($opcode === 0xA) {
                // Pong
                printf("Info: Received Pong from %s\n", $player->getPeerName());
                return WebSocketDecodeError::UnsupportedOpcode;
            } else {
                // Le funny opcode
                return WebSocketDecodeError::UnsupportedOpcode;
            }
        }
    }
}

/**
 * Perform WebSocket handshake.
 *
 * @param resource $client The client stream socket.
 * @return bool True on success, false on failure.
 */
function ws_handshake($client): bool {
    $client_header = fread($client, 1024);
    $lines = explode("\r\n", $client_header);

    $headers = [];

    foreach ($lines as $line) {
        if (preg_match("/^([^:]+): (.+)$/", $line, $matches)) {
            $headers[strtolower(trim($matches[1]))] = trim($matches[2]);
        }
    }
    if (!isset($headers["sec-websocket-key"])) {
        fprintf(STDERR, "Error: No Sec-WebSocket-Key header found\n");
        fwrite($client, "HTTP/1.1 400 Bad Request\r\n\r\n");
        return false;
    }

    $sec_websocket_key = $headers["sec-websocket-key"];
    $le_magic_string = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
    $sec_websocket_accept = base64_encode(pack("H*", sha1($sec_websocket_key . $le_magic_string)));

    $upgrade_headers = [
        "HTTP/1.1 101 Switching Protocols",
        "Upgrade: websocket",
        "Connection: Upgrade",
        "Sec-WebSocket-Accept: $sec_websocket_accept",
    ];
    fwrite($client, sprintf("%s\r\n\r\n", implode("\r\n", $upgrade_headers)));
    return true;
}

/**
 * Encode and send a message over WebSocket.
 *
 * @param string $message The message to send.
 * @param resource|array<resource> $clients A single client resource or an array of client resources.
 * @return void
 */
function ws_send(string $message, $clients): void {
    $payload = $message;
    $payload_len = strlen($payload);

    $frame_header = "";
    // FIN bit + Opcode (1 for text frame)
    $frame_header .= chr(0x81);

    if ($payload_len <= 125) {
        // Mask bit (0) + Payload length (7 bits)
        $frame_header .= chr($payload_len);
    } elseif ($payload_len <= 65535) {
        // Mask bit (0) + 126 (16-bit length follows)
        $frame_header .= chr(126);
        // Payload length (16 bits, network byte order)
        $frame_header .= pack("n", $payload_len);
    } else {
        // Mask bit (0) + 127 (64-bit length follows)
        $frame_header .= chr(127);
        // Payload length (64 bits, network byte order)
        $frame_header .= pack("J", $payload_len);
    }

    $frame = $frame_header . $payload;

    if (is_resource($clients)) {
        @fwrite($clients, $frame);
    } elseif (is_array($clients)) {
        foreach ($clients as $client) {
            if (is_resource($client)) {
                @fwrite($client, $frame);
            }
        }
    }
}
