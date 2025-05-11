<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set("memory_limit", "512M");

// Define constants first
const GAME_WIDTH = 31;
const GAME_HEIGHT = 13;
const BREAKABLE_WALL_DENSITY = 0.2; // chance of a breakable wall in a cell (0.0 - 1.0)
const BALLOON_COUNT = 5;
const TICK_LENGTH = 1; // seconds
const PLAYER_SPEED = 100;


enum WebSocketDecodeError {
    case ClientDisconnect;
    case InsufficientData;
    case UnmaskedFrame;
    case UnsupportedOpcode;
    case FragmentedMessage;
}

require_once __DIR__ . "/env.php";
require_once __DIR__ . "/Player.php";
require_once __DIR__ . "/Tile.php";

/** @var array<Player> $players */
$players = array();

/** @var array<array<Tile>> $map */
$map = array();

/**
 * Generates the game map.
 *
 * @param array<array<Tile>> &$map The map array to modify (passed by reference).
 * @return void
 */
function generate_map(array &$map): void {
    $map = [];

    for ($y = 0; $y < GAME_HEIGHT; $y++) {
        $map[$y] = [];
        for ($x = 0; $x < GAME_WIDTH; $x++) {
            if ($x == 0 || $y == 0 || $x == GAME_WIDTH - 1 || $y == GAME_HEIGHT - 1 || ($x % 2 === 0 && $y % 2 === 0)) {
                $map[$y][$x] = Tile::Wall;
            } elseif (mt_rand(0, 100) < BREAKABLE_WALL_DENSITY * 100) {
                $map[$y][$x] = Tile::BreakableWall;
            } else {
                $map[$y][$x] = Tile::Grass;
            }
        }
    }

    // Clear space around player spawn point
    $map[1][1] = Tile::Grass;
    $map[1][2] = Tile::Grass;
    $map[2][1] = Tile::Grass;
}

generate_map($map);


printf("Starting server...\n");
$server = stream_socket_server(
    sprintf("tcp://%s:%s", HOST, PORT),
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
);

if (!$server) {
    fprintf(STDERR, "Error: starting server failed for %s:%s: [%d] %s\n", HOST, PORT, $errno, $errstr);
    exit(1);
}

printf("Server started on %s:%s\n", HOST, PORT);

$prev_time = microtime(true);

while (true) {
    $changed = array($server);
    $changed = array_merge($changed, array_map(fn($p) => $p->getClient(), $players));

    $write = null;
    $except = null;

    stream_select($changed, $write, $except, TICK_LENGTH);

    if (in_array($server, $changed)) {
        $client = stream_socket_accept($server);
        if (!$client) continue;

        if (!ws_handshake($client)) {
            fclose($client);
            continue;
        }

        $player = new Player($client);
        $players[$player->getPeerName()] = $player; // Store player by peer name for easy lookup
        printf("New player connected: %s\n", $player->getPeerName());

        ws_send(
            json_encode(array(
                "type" => "connection",
                "map_width" => GAME_WIDTH,
                "map_height" => GAME_HEIGHT,
                "map" => $map,
                "players" => $players,
                "player_speed" => PLAYER_SPEED,
            )),
            $player->getClient()
        );
    }

    foreach ($changed as $client) {
        if ($client === $server) {
            continue;
        }

        $current_player = $players[stream_socket_get_name($client, true)] ?? null;
        if ($current_player === null) {
            continue;
        }

        $data_read = fread($client, 8192);
        if ($data_read === false || $data_read === "") {
            printf("Player disconnected: %s\n", $current_player->getPeerName());
            unset($players[$current_player->getPeerName()]);
            continue;
        }

        $current_player->appendToSocketBuffer($data_read);

        // Process all complete messages in the buffer
        while (strlen($current_player->getSocketBuffer()) > 0) {
            $bytes_consumed_this_frame = 0;
            $decoded_result = ws_decode_message_from_buffer($current_player, $bytes_consumed_this_frame);

            if ($decoded_result === WebSocketDecodeError::InsufficientData) {
                // Not enough data in the current buffer to form a complete frame.
                // $bytes_consumed_this_frame should be 0. Nothing to consume from buffer yet.
                break;
            }

            // If we are here, a frame was processed (successfully or with a detectable error leading to consumption).
            // Consume the bytes related to this processed frame.
            if ($bytes_consumed_this_frame > 0) {
                $current_player->consumeFromSocketBuffer($bytes_consumed_this_frame);
            } else {
                // This state (not InsufficientData, but 0 bytes consumed) indicates an issue
                // in ws_decode_message_from_buffer's logic for setting bytes_consumed on error or for empty valid frames.
                // All valid frames (even empty ones) or error-detected frames should consume header bytes.
                fprintf(STDERR, "Critical Error: ws_decode_message_from_buffer returned non-InsufficientData with 0 bytes_consumed. Result type: %s. Player: %s. Breaking buffer processing for safety.\n", gettype($decoded_result), $current_player->getPeerName());
                break; // Safety break to prevent potential infinite loops.
            }

            if ($decoded_result === WebSocketDecodeError::FragmentedMessage) {
                // A fragment was processed. Loop again to process the next part of the buffer.
                // handle_websocket_decode_error might log this if needed.
                // If buffer is now empty, the outer while loop condition will handle it.
                continue;
            }

            if ($decoded_result instanceof WebSocketDecodeError) {
                // Handle terminal errors for this frame (ClientDisconnect, UnmaskedFrame, UnsupportedOpcode)
                handle_websocket_decode_error($decoded_result, $current_player, $players);
                // If player was disconnected or a critical error occurred, stop processing their buffer.
                if (!isset($players[$current_player->getPeerName()])) {
                    break; // Player removed
                }
                // For other errors that didn't remove player, still break processing this buffer for this tick.
                break;
            } else {
                // Successfully decoded a complete message
                printf("Received from %s: %s\n", $current_player->getPeerName(), $decoded_result);

                // Handle the actual game logic based on $decoded_result
                $message_data = json_decode($decoded_result, true);
                if (is_array($message_data) && isset($message_data['vel_x']) && isset($message_data['vel_y'])) {
                    $current_player->setVelX((int)$message_data['vel_x']);
                    $current_player->setVelY((int)$message_data['vel_y']);
                    // TODO: Add further game logic, like broadcasting updates or validating moves.
                } else {
                    fprintf(STDERR, "Warning: Received malformed JSON or unexpected message structure from %s: %s\n", $current_player->getPeerName(), $decoded_result);
                }
            }
        }
    }
}

/**
 * Handles WebSocket decode errors.
 *
 * @param WebSocketDecodeError $error The error that occurred.
 * @param Player $player The player associated with the error.
 * @param array<Player> &$players The array of all players (passed by reference to allow removal).
 * @return void
 */
function handle_websocket_decode_error(WebSocketDecodeError $error, Player $player, array &$players): void {
    $peerName = $player->getPeerName();
    switch ($error) {
        case WebSocketDecodeError::ClientDisconnect:
            printf("Player disconnected: %s\n", $peerName);
            unset($players[$peerName]);
            break;
        // InsufficientData and FragmentedMessage are typically handled by the buffer loop,
        // but if they reach here, it might indicate an issue or a final fragment being processed.
        // For now, we'll log if they are passed directly.
        case WebSocketDecodeError::InsufficientData:
            // This case should ideally be handled by the loop calling ws_decode_message_from_buffer,
            // by breaking and waiting for more data. If it gets here, it's unexpected.
            fprintf(STDERR, "Info: Insufficient data for player %s, waiting for more.\n", $peerName);
            break;
        case WebSocketDecodeError::FragmentedMessage:
            // This indicates a fragment was processed, but the message is not yet complete.
            // The buffer loop should continue accumulating data.
            fprintf(STDERR, "Info: Processing fragmented message for player %s.\n", $peerName);
            break;
        case WebSocketDecodeError::UnmaskedFrame:
            fprintf(STDERR, "Error: WebSocket decode error for player %s: Unmasked frame\n", $peerName);
            unset($players[$peerName]);
            break;
        case WebSocketDecodeError::UnsupportedOpcode:
            fprintf(STDERR, "Error: WebSocket decode error for player %s: Unsupported opcode\n", $peerName);
            unset($players[$peerName]);
            break;
        default:
            fprintf(STDERR, "Error: Unknown WebSocket decode error for player %s\n", $peerName);
            unset($players[$peerName]);
            break;
    }
}

/**
 * Decode an incoming WebSocket message from the player's buffer.
 *
 * @param Player $player The player whose buffer is to be processed.
 * @param int &$bytes_consumed Output parameter for the number of bytes consumed from the buffer.
 * @return string|WebSocketDecodeError The decoded payload or an error enum.
 */
function ws_decode_message_from_buffer(Player $player, int &$bytes_consumed): string | WebSocketDecodeError {
    $data = $player->getSocketBuffer();
    $bytes_consumed = 0; // Initialize

    $fragmented_message = $player->getFragmentedMessage();
    $fragmented_opcode = $player->getFragmentedOpcode();

    $len = strlen($data);
    if ($len < 2) { // Minimum length for a WebSocket frame header
        return WebSocketDecodeError::InsufficientData;
    }

    $header = unpack("Cfirst/Csecond", $data);
    $fin = ($header["first"] & 0x80) === 0x80;
    $opcode = $header["first"] & 0x0F;
    $masked = ($header["second"] & 0x80) === 0x80;
    $payload_len_indicator = $header["second"] & 0x7F;

    $current_offset = 2; // Start after the first 2 header bytes

    $actual_payload_len = $payload_len_indicator;

    if ($payload_len_indicator === 126) {
        if ($len < $current_offset + 2) return WebSocketDecodeError::InsufficientData; // Need 2 more bytes for 16-bit length
        $actual_payload_len = unpack("n", substr($data, $current_offset, 2))[1];
        $current_offset += 2;
    } elseif ($payload_len_indicator === 127) {
        if ($len < $current_offset + 8) return WebSocketDecodeError::InsufficientData; // Need 8 more bytes for 64-bit length
        $actual_payload_len = unpack("J", substr($data, $current_offset, 8))[1]; // Assuming length fits in signed 64-bit int
        $current_offset += 8;
    }

    // RFC 6455, Section 5.1: "All frames sent from client to server have this bit [MASK] set to 1."
    // This applies to all frame types.
    if (!$masked) {
        // The header bytes read so far (up to $current_offset) constitute the malformed frame's header.
        $bytes_consumed = $current_offset;
        return WebSocketDecodeError::UnmaskedFrame;
    }

    // If masked, proceed to read mask key
    $mask_key_len = 4;
    if ($len < $current_offset + $mask_key_len) {
        return WebSocketDecodeError::InsufficientData; // Not enough data for mask key
    }
    $mask_key = substr($data, $current_offset, 4);
    $current_offset += $mask_key_len;

    // Check if there's enough data for the payload itself
    if ($len < $current_offset + $actual_payload_len) {
        return WebSocketDecodeError::InsufficientData; // Not enough data for the full payload
    }

    // If we reach here, a full frame (header + mask key + payload) is available in the buffer.
    $payload_data = substr($data, $current_offset, $actual_payload_len);
    $bytes_consumed = $current_offset + $actual_payload_len; // Total bytes for this complete frame

    $decoded_payload = "";
    // Unmask payload data (all client-to-server frames are masked)
    for ($i = 0; $i < $actual_payload_len; $i++) {
        $decoded_payload .= $payload_data[$i] ^ $mask_key[$i % 4];
    }

    // Handle opcodes
    if ($opcode === 0x8) { // Connection Close
        // $bytes_consumed is already set for the full close frame.
        return WebSocketDecodeError::ClientDisconnect;
    }

    // Handle fragmented messages
    if (!$fin) { // This is a fragment, but not the final one
        if ($opcode !== 0x0) { // First fragment of a message (e.g., text, binary)
            if ($fragmented_message !== "") {
                // Protocol error: Received a new initial fragment while another was in progress.
                // Clear old state and start new. Consider if this should be a harder error.
                fprintf(STDERR, "Warning: New fragmented message (opcode %x) started for player %s before previous completed. Old one discarded.\n", $opcode, $player->getPeerName());
                $player->setFragmentedMessage("");
                $player->setFragmentedOpcode(0);
            }
            $player->setFragmentedOpcode($opcode);
            $player->setFragmentedMessage($decoded_payload);
        } else { // Continuation fragment (opcode 0x0)
            if ($fragmented_message === "") {
                // Protocol error: Continuation frame received without a prior starting fragment.
                // $bytes_consumed is set for this malformed frame.
                return WebSocketDecodeError::UnsupportedOpcode; // Or a more specific error
            }
            $player->setFragmentedMessage($fragmented_message . $decoded_payload);
        }
        // $bytes_consumed is set for this fragment.
        return WebSocketDecodeError::FragmentedMessage; // Indicate message is still in progress
    } else { // This is the final fragment (fin=1)
        if ($opcode === 0x0) { // Final continuation fragment
            if ($fragmented_message === "") {
                // Protocol error: Final continuation frame received without a prior starting fragment.
                // $bytes_consumed is set for this malformed frame.
                return WebSocketDecodeError::UnsupportedOpcode;
            }
            $final_message = $fragmented_message . $decoded_payload;
            $message_opcode = $player->getFragmentedOpcode();
            $player->setFragmentedMessage("");
            $player->setFragmentedOpcode(0);

            // $bytes_consumed is set for this final fragment.
            // Now process $final_message based on $message_opcode
            if ($message_opcode === 0x1 || $message_opcode === 0x2) { // Text or Binary
                return $final_message;
            } else {
                return WebSocketDecodeError::UnsupportedOpcode; // Original opcode was not text/binary
            }
        } else { // A single, non-fragmented message (fin=1, opcode != 0x0, e.g. text, binary, ping, pong)
            if ($fragmented_message !== "") {
                // Protocol error: Received a new non-fragmented message while a fragmented one was in progress.
                fprintf(STDERR, "Warning: New non-fragmented message (opcode %x) received for player %s while fragmented one in progress. Fragmented discarded.\n", $opcode, $player->getPeerName());
                $player->setFragmentedMessage("");
                $player->setFragmentedOpcode(0);
                // Proceed with the new message, $bytes_consumed is for this new message.
            }

            // $bytes_consumed is set for this single message.
            if ($opcode === 0x1 || $opcode === 0x2) { // Text or Binary
                return $decoded_payload;
            } elseif ($opcode === 0x9) { // Ping
                // TODO: Handle Ping (e.g., send Pong)
                // For now, just acknowledge consumption. Could return a specific type or handle directly.
                fprintf(STDERR, "Info: Received Ping from %s\n", $player->getPeerName());
                // ws_send_pong($player, $decoded_payload); // Example
                return WebSocketDecodeError::UnsupportedOpcode; // Or treat as "handled, no app message"
            } elseif ($opcode === 0xA) { // Pong
                // TODO: Handle Pong (e.g., update keep-alive status)
                fprintf(STDERR, "Info: Received Pong from %s\n", $player->getPeerName());
                return WebSocketDecodeError::UnsupportedOpcode; // Or treat as "handled, no app message"
            } else {
                return WebSocketDecodeError::UnsupportedOpcode; // Other control frames or unknown opcodes
            }
        }
    }
    // Fallback, should ideally not be reached if all paths are handled
    return WebSocketDecodeError::UnsupportedOpcode;
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

/**
 * Signum function.
 *
 * @param int|float $x The input value.
 * @return int -1 if x < 0, 0 if x == 0, 1 if x > 0
 */
function signum(int|float $x): int {
    if ($x < 0) return -1;
    if ($x > 0) return 1;
    return 0;
}
