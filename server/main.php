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
    $read = array($server);
    foreach ($players as $player) {
        array_push($read, $player->getClient());
    }

    $write = null;
    $except = null;

    stream_select($read, $write, $except, TICK_LENGTH);

    if (in_array($server, $read)) {
        $client = stream_socket_accept($server);
        if (!$client) {
            continue;
        }

        $player = new Player($client);
        $peerName = $player->getPeerName();

        printf("Info: New player connected: %s\n", $peerName);

        stream_set_blocking($client, true);
        if (!ws_handshake($client)) {
            fprintf(STDERR, "Error: WebSocket handshake with %s failed\n", $peerName);
            fclose($client);
            continue;
        }
        stream_set_blocking($client, false);
        printf("Info: WebSocket handshake with %s completed\n", $peerName);

        array_push($players, $player);

        ws_send(
            json_encode(array(
                "type" => "connection",
                "map_width" => GAME_WIDTH,
                "map_height" => GAME_HEIGHT,
                "map" => $map,
                "players" => $players,
                "player_speed" => PLAYER_SPEED,
            )),
            $client,
        );

        $other_players = array_filter($players, fn($p) => $p !== $player);

        // Extract client resources from the other Player objects
        $other_player_clients = array_map(fn(Player $p) => $p->getClient(), $other_players);
        ws_send(
            json_encode(array(
                "type" => "new_player",
                "player" => $player,
            )),
            $other_player_clients, // Send to the actual socket resources
        );

        $founds_socket = array_search($client, $read);
        unset($read[$founds_socket]);
    }

    /** @var array<string, Player> */
    $players_to_update = array();

    foreach ($read as $client) {
        $currentPlayer = null;
        $playerIndex = -1;
        foreach ($players as $index => $player) {
            if ($player->getClient() === $client) {
                $currentPlayer = $player;
                $playerIndex = $index;
                break;
            }
        }

        if (!$currentPlayer) {
            fprintf(STDERR, "Warning: Received data from unknown socket.\n");
            continue;
        }

        $peerName = $currentPlayer->getPeerName();
        $data = fread($client, 8192); // Read up to 8KB

        // Check for disconnection or read error
        if ($data === false || $data === "") {
            printf("Info: Player %s disconnected.\n", $peerName);
            fclose($client);
            unset($players[$playerIndex]);

            // Re-index array
            $players = array_values($players);
            continue;
        }

        // Decode the WebSocket frame
        $decoded_message = ws_decode($data);

        if ($decoded_message === null) {
            fprintf(STDERR, "Info: Received invalid frame or close request from %s. Disconnecting.\n", $peerName);
            fclose($client);
            unset($players[$playerIndex]);
            $players = array_values($players);

            $player_clients = array_map(fn(Player $p) => $p->getClient(), $players);

            ws_send(
                json_encode(array(
                    "type" => "player_disconnected",
                    // "player_id" => $player->getId(),
                    "player_id" => $peerName,
                )),
                $player_clients,
            );
            continue;
        }

        printf("Received from %s: %s\n", $peerName, $decoded_message);

        $message = json_decode($decoded_message, true);
        $players_to_update[$peerName] = $message;
    }

    $delta_time = microtime(true) - $prev_time;
    $prev_time = microtime(true);

    // Update player velocities
    foreach ($players_to_update as $peerName => $message) {
        foreach ($players as $player) {
            if ($player->getPeerName() === $peerName) {
                // Only update velocity from client messages
                if (isset($message["vel_x"]) && isset($message["vel_y"])) {
                    $player->setVelX(intval($message["vel_x"]));
                    $player->setVelY(intval($message["vel_y"]));
                }
                break;
            }
        }
    }

    // Extract client resources from Player objects
    $client_resources = array_map(fn(Player $player) => $player->getClient(), $players);

    // Only send velocity data in updates
    $velocity_updates = array_map(function ($player) {
        return [
            'id' => $player->getId(),
            'vel_x' => $player->getVelX(),
            'vel_y' => $player->getVelY(),
        ];
    }, $players);

    ws_send(
        json_encode(array(
            "type" => "update",
            "players" => $velocity_updates,
        )),
        $client_resources,
    );
}

/**
 * Decode an incoming WebSocket message.
 *
 * @param string $data The raw frame data received from the client.
 * @return string|null The decoded payload, or null on error or if the frame is incomplete.
 */
function ws_decode(string $data): ?string {
    $len = strlen($data);
    if ($len < 2) {
        return null; // Not enough data for header
    }

    $header = unpack("Cfirst/Csecond", $data);
    $fin = ($header["first"] & 0x80) === 0x80;
    $opcode = $header["first"] & 0x0F;
    $masked = ($header["second"] & 0x80) === 0x80;
    $payload_len = $header["second"] & 0x7F;

    $offset = 2;

    if ($payload_len === 126) {
        if ($len < 4) return null; // Need 2 more bytes for length
        $payload_len = unpack("n", substr($data, $offset, 2))[1];
        $offset += 2;
    } elseif ($payload_len === 127) {
        if ($len < 10) return null; // Need 8 more bytes for length
        $payload_len = unpack("J", substr($data, $offset, 8))[1];
        $offset += 8;
    }

    if (!$masked) {
        fprintf(STDERR, "Error: Received unmasked frame from client\n");
        return null;
    }

    if ($len < $offset + 4) return null; // Need 4 bytes for mask key
    $mask_key = substr($data, $offset, 4);
    $offset += 4;

    if ($len < $offset + $payload_len) return null; // Frame data incomplete

    $payload = substr($data, $offset, $payload_len);
    $decoded_payload = "";

    for ($i = 0; $i < $payload_len; $i++) {
        $decoded_payload .= $payload[$i] ^ $mask_key[$i % 4];
    }

    // Handle 0x8 (close) opcode
    if ($opcode === 0x8) {
        return null;
    }

    // Only handle text frames (opcode 0x1) for now
    if ($opcode !== 0x1) {
        fprintf(STDERR, "Warning: Received non-text frame (opcode: %d)\n", $opcode);
        return null;
    }


    // TODO: Handle fragmented messages (FIN bit is 0)

    return $decoded_payload;
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
        fclose($client);
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
