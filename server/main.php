<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set("memory_limit", "512M");

const GAME_WIDTH = 31;
const GAME_HEIGHT = 13;
const BREAKABLE_WALL_DENSITY = 0.125; // chance of a breakable wall in a cell (0.0 - 1.0)
const BALLOON_COUNT = 10;
const TICK_LENGTH = 1;
const PLAYER_SPEED = 5;
const STREAM_SELECT_TIMEOUT_SEC = 0;
const STREAM_SELECT_TIMEOUT_USEC = 1000;

require_once __DIR__ . "/env.php";
require_once __DIR__ . "/ws_utils.php";
require_once __DIR__ . "/utils.php";
require_once __DIR__ . "/Player.php";
require_once __DIR__ . "/Ballon.php";
require_once __DIR__ . "/Tile.php";
require_once __DIR__ . "/WebSocketDecodeError.php";

/** @var array<Player> $players */
$players = array();

/** @var array<Ballon> $ballons */
$ballons = array();

/** @var array<array<Tile>> $map */
$map = array();

generate_map($map);

for ($i = 0; $i < BALLOON_COUNT; $i++) {
    $start_x = 0;
    $start_y = 0;
    $to_x = 0;
    $to_y = 0;
    $valid_spawn = false;
    do {
        $start_x = rand(1, GAME_WIDTH - 2);
        $start_y = rand(1, GAME_HEIGHT - 2);

        if ($map[$start_y][$start_x] === Tile::Grass) {
            $adjacent_grass_tiles = array();
            $potential_neighbors = array(
                array("y" => $start_y - 1, "x" => $start_x),
                array("y" => $start_y + 1, "x" => $start_x),
                array("y" => $start_y, "x" => $start_x - 1),
                array("y" => $start_y, "x" => $start_x + 1),
            );

            foreach ($potential_neighbors as $neighbor) {
                $nx = $neighbor["x"];
                $ny = $neighbor["y"];

                if ($nx >= 0 && $nx < GAME_WIDTH && $ny >= 0 && $ny < GAME_HEIGHT) {
                    if ($map[$ny][$nx] === Tile::Grass) {
                        array_push($adjacent_grass_tiles, $neighbor);
                    }
                }
            }
            if (!empty($adjacent_grass_tiles)) {
                $valid_spawn = true;
                $random_neighbor_key = array_rand($adjacent_grass_tiles);
                $chosen_neighbor = $adjacent_grass_tiles[$random_neighbor_key];
                $to_x = $chosen_neighbor["x"];
                $to_y = $chosen_neighbor["y"];
            }
        }
    } while (!$valid_spawn);

    array_push($ballons, new Ballon($start_x, $start_y, $to_x, $to_y));
}

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
$game_tick_accumulator = 0.0;

while (true) {
    $changed = array($server);
    $changed = array_merge($changed, array_map(fn($p) => $p->getClient(), $players));

    $write = null;
    $except = null;

    stream_select($changed, $write, $except, STREAM_SELECT_TIMEOUT_SEC, STREAM_SELECT_TIMEOUT_USEC);

    if (in_array($server, $changed)) {
        $client = stream_socket_accept($server);
        if (!$client) continue;

        if (!ws_handshake($client)) {
            fclose($client);
            continue;
        }

        $player = new Player($client);
        $peer_name = $player->getPeerName();
        $players[$peer_name] = $player;
        printf("New player connected: %s\n", $peer_name);

        ws_send(
            json_encode(array(
                "type" => "connection",
                "map_width" => GAME_WIDTH,
                "map_height" => GAME_HEIGHT,
                "map" => $map,
                "players" => $players,
                "ballons" => $ballons,
                "accumulated_time" => $game_tick_accumulator,
                "player_speed" => PLAYER_SPEED,
                "tick_length" => TICK_LENGTH,
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

            $decoded_result = ws_decode($current_player, $bytes_consumed_this_frame);

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

    $current_time = microtime(true);
    $delta_time = $current_time - $prev_time;
    $prev_time = $current_time;

    $game_tick_accumulator += $delta_time;

    while ($game_tick_accumulator >= TICK_LENGTH) {
        foreach ($ballons as $ballon) {
            $ballon->setFromX($ballon->getToX());
            $ballon->setFromY($ballon->getToY());

            $current_x = $ballon->getFromX();
            $current_y = $ballon->getFromY();

            $new_target_determined = false;
            do {
                $adjacent_grass_tiles = array();
                $potential_neighbors = array(
                    array("y" => $current_y - 1, "x" => $current_x),
                    array("y" => $current_y + 1, "x" => $current_x),
                    array("y" => $current_y, "x" => $current_x - 1),
                    array("y" => $current_y, "x" => $current_x + 1),
                );

                foreach ($potential_neighbors as $neighbor) {
                    $nx = $neighbor["x"];
                    $ny = $neighbor["y"];

                    if (
                        $nx >= 0 && $nx < GAME_WIDTH &&
                        $ny >= 0 && $ny < GAME_HEIGHT &&
                        isset($map[$ny][$nx]) && $map[$ny][$nx] === Tile::Grass
                    ) {
                        array_push($adjacent_grass_tiles, $neighbor);
                    }
                }

                if (!empty($adjacent_grass_tiles)) {
                    $random_neighbor_key = array_rand($adjacent_grass_tiles);
                    $chosen_neighbor = $adjacent_grass_tiles[$random_neighbor_key];
                    $ballon->setToX($chosen_neighbor["x"]);
                    $ballon->setToY($chosen_neighbor["y"]);
                } else {
                    $ballon->setToX($current_x);
                    $ballon->setToY($current_y);
                }
                $new_target_determined = true;
            } while (!$new_target_determined);
        }

        ws_send(
            json_encode(array(
                "type" => "game_update",
                "ballons" => $ballons,
            )),
            get_clients($players)
        );

        $game_tick_accumulator -= TICK_LENGTH;
    }
}
