<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set("memory_limit", "512M");

const GAME_WIDTH = 31;
const GAME_HEIGHT = 13;
const BREAKABLE_WALL_DENSITY = 0.1; // chance of a breakable wall in a cell (0.0 - 1.0)
const BALLOON_COUNT = 5;
const TICK_LENGTH = 1; // seconds
const PLAYER_SPEED = 5;
const STREAM_SELECT_TIMEOUT_SEC = 0;
const STREAM_SELECT_TIMEOUT_USEC = 1;

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

// Initialize balloons
for ($i = 0; $i < BALLOON_COUNT; $i++) {
    $start_x = 0;
    $start_y = 0;
    do {
        $start_x = rand(1, GAME_WIDTH - 2);
        $start_y = rand(1, GAME_HEIGHT - 2);
    } while ($map[$start_y][$start_x] !== Tile::Grass);

    // $vel_x = rand(0, 1) * 2 - 1;
    // $vel_y = (rand(0, 1) === 0) ? (rand(0, 1) * 2 - 1) : 0;
    // if ($vel_x !== 0 && $vel_y !== 0) {
    //     if (rand(0, 1) === 0) $vel_x = 0;
    //     else $vel_y = 0;
    // }
    // if ($vel_x === 0 && $vel_y === 0) $vel_x = 1; // Ensure it moves

    // $ballons[] = new Ballon((float)$start_x, (float)$start_y, $vel_x, $vel_y);
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
$game_tick_accumulator = 0.0; // Accumulator for game tick timing

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
        printf("Game Tick: Executing %f-second interval logic.\n", (float)TICK_LENGTH);

        // Move balloons
        foreach ($ballons as $ballon) {
            $new_pos_x = $ballon->getPosX() + $ballon->getVelX();
            $new_pos_y = $ballon->getPosY() + $ballon->getVelY();

            // Basic boundary and collision detection (simplified)
            // Check map boundaries
            if ($new_pos_x < 0 || $new_pos_x >= GAME_WIDTH || $map[(int)round($ballon->getPosY())][(int)round($new_pos_x)] === Tile::Wall || $map[(int)round($ballon->getPosY())][(int)round($new_pos_x)] === Tile::BreakableWall) {
                $ballon->setVelX($ballon->getVelX() * -1); // Reverse X velocity
                // Adjust position to be just before the wall if it would have passed through
                $new_pos_x = $ballon->getPosX();
            } else {
                $ballon->setPosX($new_pos_x);
            }

            if ($new_pos_y < 0 || $new_pos_y >= GAME_HEIGHT || $map[(int)round($new_pos_y)][(int)round($ballon->getPosX())] === Tile::Wall || $map[(int)round($new_pos_y)][(int)round($ballon->getPosX())] === Tile::BreakableWall) {
                $ballon->setVelY($ballon->getVelY() * -1); // Reverse Y velocity
                // Adjust position to be just before the wall
                $new_pos_y = $ballon->getPosY();
            } else {
                $ballon->setPosY($new_pos_y);
            }

            // Ensure balloons don't get stuck by alternating direction if both velocities become 0 after hitting corners
            if ($ballon->getVelX() === 0 && $ballon->getVelY() === 0) {
                // This logic might need to be more sophisticated to prevent getting stuck
                // For now, just give it a new random direction
                $ballon->setVelX(rand(0, 1) * 2 - 1);
                $ballon->setVelY((rand(0, 1) === 0) ? (rand(0, 1) * 2 - 1) : 0);
                if ($ballon->getVelX() !== 0 && $ballon->getVelY() !== 0) {
                    if (rand(0, 1) === 0) $ballon->setVelX(0);
                    else $ballon->setVelY(0);
                }
                if ($ballon->getVelX() === 0 && $ballon->getVelY() === 0) $ballon->setVelX(1);
            }
        }

        // TODO: Add other game logic updates here that should run once per TICK_LENGTH
        // For example: move NPCs, update environment, check game conditions.

        ws_send(
            json_encode(array(
                "type" => "game_update",
                "ballons" => $ballons, // Add balloons to game_update message
                // You can add more data to the game_update message if needed
            )),
            get_clients($players)
        );
        printf("Current seconds: %s\n", date("s"));



        $game_tick_accumulator -= TICK_LENGTH;
    }
}
