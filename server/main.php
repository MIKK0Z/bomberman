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
const PLAYER_SPEED = 5;

require_once __DIR__ . "/env.php";
require_once __DIR__ . "/ws_utils.php";
require_once __DIR__ . "/utils.php";
require_once __DIR__ . "/Player.php";
require_once __DIR__ . "/Tile.php";
require_once __DIR__ . "/WebSocketDecodeError.php";

/** @var array<Player> $players */
$players = array();

/** @var array<array<Tile>> $map */
$map = array();

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
        $peer_name = $player->getPeerName();
        $players[$peer_name] = $player; // Store player by peer name for easy lookup
        printf("New player connected: %s\n", $peer_name);

        ws_send(
            json_encode(array(
                "type" => "connection",
                "map_width" => GAME_WIDTH,
                "map_height" => GAME_HEIGHT,
                "map" => $map,
                "players" => $players,
                "player_speed" => PLAYER_SPEED,
                "peer_name" => $peer_name,
            )),
            $player->getClient()
        );

        ws_send(
            json_encode(array(
                "type" => "new_player",
                "peer_name" => $peer_name,
                "player" => $player,
            )),
            $player->getOtherClients($players)
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

        $peer_name = $current_player->getPeerName();

        $data_read = fread($client, 8192);
        if ($data_read === false || $data_read === "") {
            printf("Player disconnected: %s\n", $current_player->getPeerName());
            unset($players[$current_player->getPeerName()]);
            continue;
        }

        $current_player->appendToSocketBuffer($data_read);

        while (strlen($current_player->getSocketBuffer()) > 0) {
            $bytes_consumed_this_frame = 0;

            $decoded_result = ws_decode_message_from_buffer($current_player, $bytes_consumed_this_frame);

            if ($bytes_consumed_this_frame > 0) {
                $current_player->consumeFromSocketBuffer($bytes_consumed_this_frame);
            }

            if ($decoded_result instanceof WebSocketDecodeError) {
                switch ($decoded_result) {
                    case WebSocketDecodeError::InsufficientData:
                        break 2;

                    case WebSocketDecodeError::FragmentedMessage:
                        continue 2;

                    case WebSocketDecodeError::ClientDisconnect:
                        printf("Player disconnected: %s\n", $peer_name);
                        handle_player_disconnection($peer_name, $players);
                        break 2;

                    case WebSocketDecodeError::UnmaskedFrame:
                        fprintf(STDERR, "Error: WebSocket decode error for player %s: Unmasked frame\n", $peer_name);
                        handle_player_disconnection($peer_name, $players);
                        break 2;

                    case WebSocketDecodeError::UnsupportedOpcode:
                        fprintf(STDERR, "Error: WebSocket decode error for player %s: Unsupported opcode\n", $peer_name);
                        handle_player_disconnection($peer_name, $players);
                        break 2;

                    case WebSocketDecodeError::ContinuationFrameOutOfOrder:
                        fprintf(STDERR, "Error: WebSocket decode error for player %s: Continuation frame out of order\n", $peer_name);
                        handle_player_disconnection($peer_name, $players);
                        break 2;

                    default:
                        fprintf(STDERR, "Error: Unknown WebSocket decode error for player %s\n", $peer_name);
                        handle_player_disconnection($peer_name, $players);
                        break 2;
                }
            } else {
                printf("Received message from player %s: %s\n", $peer_name, $decoded_result);
                $decoded_result = json_decode($decoded_result, true);
                if (!$decoded_result || !isset($decoded_result["vel_x"], $decoded_result["vel_y"], $decoded_result["pos_x"], $decoded_result["pos_y"])) {
                    fprintf(STDERR, "Error: Invalid message format from player %s\n", $peer_name);
                    continue;
                }

                $current_player->setVelX($decoded_result["vel_x"]);
                $current_player->setVelY($decoded_result["vel_y"]);
                $current_player->setPosX($decoded_result["pos_x"]);
                $current_player->setPosY($decoded_result["pos_y"]);

                ws_send(
                    json_encode(array(
                        "type" => "player_update",
                        "peer_name" => $peer_name,
                        "player" => $current_player,
                    )),
                    get_clients($players)
                );
            }
        }
    }
}
