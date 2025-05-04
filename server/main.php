<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set("memory_limit", "512M");

require_once __DIR__ . "/env.php";
require_once __DIR__ . "/Player.php";

const GAME_WIDTH = 31;
const GAME_HEIGHT = 13;

const BREAKABLE_WALL_DENSITY = 0.1; // chance of a breakable wall in a cell (0.0 - 1.0)
const BALLOON_COUNT = 5;

const TICK_LENGTH = 1; // seconds

/** @var array<Player> $players */
$players = array();

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
        array_push($players, $player);
        printf("New player connected: %s\n", $player->getPeerName());

        stream_set_blocking($client, true);
        ws_handshake($client);
        stream_set_blocking($client, false);
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
