<?php

declare(strict_types=1);

// Include class/enum definitions
require_once __DIR__ . "/Player.php"; // Player now includes Direction enum
require_once __DIR__ . "/Tile.php";
require_once __DIR__ . "/Balloon.php"; // Include the new Balloon class

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

// --- Load configuration from .env file ---
$envPath = dirname(__DIR__) . "/.env"; // Path to .env in the parent directory
$host = "127.0.0.1"; // Default host
$port = 46089;      // Default port

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignore comments
        if (strpos(trim($line), "#") === 0 || strpos(trim($line), "//") === 0) {
            continue;
        }

        // Split key=value pairs
        if (strpos($line, "=") !== false) {
            list($key, $value) = explode("=", $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === "HOST") {
                $host = $value;
            } elseif ($key === "PORT") {
                // Ensure port is an integer
                $port = (int)$value;
            }
        }
    }
} else {
    fprintf(STDERR, "Warning: .env file not found at %s. Using default settings.\n", $envPath);
}
// --- End loading configuration ---

if (!defined('WS_HOST')) define("WS_HOST", $host);
if (!defined('WS_PORT')) define("WS_PORT", $port);

// --- Define Constants ---
define("GAME_WIDTH", 31);
define("GAME_HEIGHT", 13);
define("BREAKABLE_WALL_DENSITY", 0.1); // Increased density slightly
define("PLAYER_SPEED", 3.0); // Tiles per second (matches Player class default)
define("SERVER_TICK_RATE", 30); // Target updates per second
define("SERVER_TICK_INTERVAL_US", (int)(1_000_000 / SERVER_TICK_RATE)); // Microseconds
define("INITIAL_BALLOON_COUNT", 3); // Number of balloons to spawn
define("PLAYER_BALLOON_COLLISION_DISTANCE", 0.6); // Distance threshold for collision (tile units)
// --- End Define Constants ---

// --- Game State ---
/** @var array<int, Player> $players Keyed by Player ID */
$players = [];
/** @var int Counter for assigning unique player IDs */
$nextPlayerId = 1;
/** @var array<int, Balloon> $balloons Keyed by Balloon ID */
$balloons = [];
/** @var int Counter for assigning unique balloon IDs */
$nextBalloonId = 1;
/** @var array<int, array<int, Tile>> $gameMap */
$gameMap = generateMap(GAME_WIDTH, GAME_HEIGHT);
/** @var float Time of the last game loop tick */
$lastTickTime = microtime(true);
// --- End Game State ---

// --- Spawn Initial Balloons ---
function findSpawnableGrassTile(array $map, int $width, int $height): ?array {
    $attempts = 0;
    while ($attempts < 100) { // Limit attempts to prevent infinite loop
        $x = rand(1, $width - 2);
        $y = rand(1, $height - 2);
        // Ensure it's grass and not too close to player start areas
        $isStartPositionArea = ($y <= 2 && $x <= 2) || ($y <= 2 && $x >= $width - 3) ||
            ($y >= $height - 3 && $x <= 2) || ($y >= $height - 3 && $x >= $width - 3);
        if (isset($map[$y][$x]) && $map[$y][$x] === Tile::Grass && !$isStartPositionArea) {
            return [(float)$x + 0.5, (float)$y + 0.5]; // Spawn in center of tile
        }
        $attempts++;
    }
    return null; // Could not find a suitable spot
}

for ($i = 0; $i < INITIAL_BALLOON_COUNT; $i++) {
    $spawnPos = findSpawnableGrassTile($gameMap, GAME_WIDTH, GAME_HEIGHT);
    if ($spawnPos) {
        $balloonId = $nextBalloonId++;
        $balloons[$balloonId] = new Balloon($balloonId, $spawnPos[0], $spawnPos[1]);
    } else {
        fprintf(STDERR, "Warning: Could not find suitable spawn location for balloon %d\n", $i + 1);
    }
}
// --- End Spawn Initial Balloons ---

// --- Create TCP Socket Server using Streams ---
printf("Creating stream socket server...\n");
$server_socket = stream_socket_server(
    sprintf("tcp://%s:%d", WS_HOST, WS_PORT),
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
);

if (!$server_socket) {
    fprintf(STDERR, "stream_socket_server() failed for %s:%d: [%d] %s\n", WS_HOST, WS_PORT, $errno, $errstr);
    exit(1);
}

printf("Server listening on %s:%d\n", WS_HOST, WS_PORT);

// Use an associative array to store Player objects, keyed by resource ID for stream_select
/** @var array<int, Player> $playersByResourceId */
$playersByResourceId = [];

// --- Main Server Loop ---
while (true) {
    // Prepare arrays for stream_select using Player streams
    $read_streams = [$server_socket]; // Start with the server socket
    foreach ($playersByResourceId as $player) {
        $read_streams[] = $player->stream; // Add each player's stream
    }
    $write_streams = null;
    $except_streams = null;

    // --- Wait for activity or timeout for game loop ---
    // Use the tick interval as the timeout for stream_select
    $num_changed_streams = stream_select($read_streams, $write_streams, $except_streams, 0, SERVER_TICK_INTERVAL_US);

    if ($num_changed_streams === false) {
        // Error in stream_select (e.g., interrupted by signal)
        if (posix_errno() !== EINTR) { // Check if it's not just an interrupt
            fprintf(STDERR, "stream_select() failed: %s\n", posix_strerror(posix_errno()));
            break; // Exit loop on critical error
        }
        // If it was EINTR, just continue the loop
        $num_changed_streams = 0; // Treat as timeout for game loop logic
    }

    $currentTime = microtime(true);
    $deltaTime = $currentTime - $lastTickTime;

    // --- Game Logic Update ---
    // Update all players regardless of stream activity (driven by timeout)
    foreach ($players as $player) {
        if ($player->isAlive) { // Only update living players
            $player->updatePosition($deltaTime, $gameMap);
        } else {
            $player->updateDeathAnimation($deltaTime); // Update death animation timer
        }
        $player->lastUpdateTime = $currentTime; // Update last update time after processing
    }

    // Update all balloons
    foreach ($balloons as $balloon) {
        $balloon->updatePosition($deltaTime, $gameMap);
    }

    // --- Collision Detection (Player vs Balloon) ---
    foreach ($players as $player) {
        if (!$player->isAlive) continue; // Dead players can't collide

        foreach ($balloons as $balloon) {
            $dx = $player->x - $balloon->x;
            $dy = $player->y - $balloon->y;
            $distanceSq = ($dx * $dx) + ($dy * $dy);

            if ($distanceSq < (PLAYER_BALLOON_COLLISION_DISTANCE * PLAYER_BALLOON_COLLISION_DISTANCE)) {
                printf("Collision detected between Player %d and Balloon %d!\n", $player->id, $balloon->id);
                $player->die(); // Player dies on collision
                // Optionally remove the balloon or have other effects
                break; // Player can only die once per frame from balloons
            }
        }
    }
    // --- End Collision Detection ---

    // --- Broadcast State ---
    $stateUpdate = [];
    // Player States
    if (!empty($players)) {
        $allPlayerStates = [];
        foreach ($players as $player) {
            $allPlayerStates[] = $player->getState(); // getState now includes alive status/death frame
        }
        $stateUpdate['players'] = $allPlayerStates;
    }
    // Balloon States
    if (!empty($balloons)) {
        $allBalloonStates = [];
        foreach ($balloons as $balloon) {
            $allBalloonStates[] = $balloon->getState();
        }
        $stateUpdate['balloons'] = $allBalloonStates;
    }

    if (!empty($stateUpdate)) {
        $updateMessage = json_encode([
            "type" => "gameStateUpdate", // Consolidated update type
            "data" => $stateUpdate
        ]);
        if ($updateMessage) {
            broadcast($playersByResourceId, $updateMessage); // Send to all players
        }
    }
    $lastTickTime = $currentTime; // Record time after updates and broadcast


    // --- Handle Network Activity (if any streams changed) ---
    if ($num_changed_streams > 0) {
        // --- Handle New Connections ---
        if (in_array($server_socket, $read_streams)) {
            $new_client_stream = stream_socket_accept($server_socket, 0, $peer_name); // Use 0 timeout

            if ($new_client_stream !== false) {
                printf("New client connecting from %s...\n", $peer_name);
                stream_set_blocking($new_client_stream, true); // Block for handshake read
                $header = fread($new_client_stream, 2048);
                stream_set_blocking($new_client_stream, false); // Set back to non-blocking

                if ($header !== false && $header !== "" && perform_handshake($new_client_stream, $header)) {
                    // Assign ID and starting position
                    $playerId = $nextPlayerId++;
                    // Simple starting position logic (needs improvement for multiple players)
                    $startX = ($playerId % 2 == 1) ? 1.5 : (GAME_WIDTH - 1.5);
                    $startY = ($playerId <= 2) ? 1.5 : (GAME_HEIGHT - 1.5);

                    $newPlayer = new Player($new_client_stream, $peer_name, $playerId, (float)$startX, (float)$startY);
                    $players[$playerId] = $newPlayer; // Store by Player ID
                    $playersByResourceId[$newPlayer->getResourceId()] = $newPlayer; // Store by Resource ID
                    printf("Client connected successfully (%s), Player ID: %d\n", $peer_name, $playerId);

                    // --- Send initial state to the new player ---
                    // 1. Assign ID
                    $assignIdMsg = json_encode(["type" => "assignId", "id" => $playerId]);
                    if ($assignIdMsg) $newPlayer->send($assignIdMsg);

                    // 2. Send Map
                    $mapMsg = json_encode(["type" => "map", "data" => $gameMap]);
                    if ($mapMsg) $newPlayer->send($mapMsg);

                    // 3. Send state of *all* current players (including the new one)
                    $initialPlayerStates = [];
                    foreach ($players as $p) {
                        $initialPlayerStates[] = $p->getState();
                    }
                    $playerStateMsg = json_encode(["type" => "initialPlayers", "data" => $initialPlayerStates]);
                    if ($playerStateMsg) $newPlayer->send($playerStateMsg);

                    // 4. Send state of *all* current balloons
                    $initialBalloonStates = [];
                    foreach ($balloons as $b) {
                        $initialBalloonStates[] = $b->getState();
                    }
                    $balloonStateMsg = json_encode(["type" => "initialBalloons", "data" => $initialBalloonStates]);
                    if ($balloonStateMsg) $newPlayer->send($balloonStateMsg);


                    printf("Sent initial state (ID, Map, Players, Balloons) to %s.\n", $peer_name);

                    // --- Notify other players about the new player ---
                    $newPlayerState = $newPlayer->getState();
                    $joinMessage = json_encode(["type" => "playerJoin", "data" => $newPlayerState]);
                    if ($joinMessage) {
                        broadcast($playersByResourceId, $joinMessage, $newPlayer->getResourceId()); // Exclude sender
                    }
                } else {
                    fprintf(STDERR, "Handshake failed or header read failed for %s. Closing connection.\n", $peer_name);
                    fclose($new_client_stream);
                }
            } else {
                fprintf(STDERR, "stream_socket_accept() failed.\n");
            }
            // Remove the server socket from the read array as it's handled
            $server_key = array_search($server_socket, $read_streams);
            unset($read_streams[$server_key]);
        } // End handling new connection


        // --- Handle Data from Existing Players ---
        foreach ($read_streams as $stream_with_data) {
            $resourceId = (int)$stream_with_data;

            if (!isset($playersByResourceId[$resourceId])) {
                fprintf(STDERR, "Received data from unknown stream resource ID: %d\n", $resourceId);
                @fclose($stream_with_data); // Close unknown stream
                continue;
            }

            $player = $playersByResourceId[$resourceId];
            $data = @fread($player->stream, 4096); // Read more data

            // Handle disconnection or read error
            if ($data === false || $data === "") {
                handle_disconnect($player, $players, $playersByResourceId);
                continue; // Move to the next stream
            } else {
                // Decode the WebSocket frame(s) - might receive multiple messages
                // Basic decode assumes one message per read for simplicity here.
                // A robust server would buffer and parse frames properly.
                $decoded_message = decode_frame($data);
                if ($decoded_message !== null) {
                    printf("Received message from %s (ID: %d): %s\n", $player->peerName, $player->id, $decoded_message);

                    // --- Process Client Message ---
                    $messageObj = json_decode($decoded_message, true); // Decode as associative array
                    if (is_array($messageObj) && isset($messageObj['type'])) {
                        switch ($messageObj['type']) {
                            case 'key':
                                // Only process key presses if the player is alive
                                if ($player->isAlive && isset($messageObj['key']) && isset($messageObj['state'])) {
                                    if ($messageObj['state'] === 'down') {
                                        $player->handleKeyPress($messageObj['key']);
                                    } elseif ($messageObj['state'] === 'up') {
                                        $player->handleKeyRelease($messageObj['key']);
                                    }
                                }
                                break;
                            // Add other message types later (e.g., 'placeBomb')
                            default:
                                printf("Unknown message type '%s' from player %d\n", $messageObj['type'], $player->id);
                                break;
                        }
                    } else {
                        fprintf(STDERR, "Invalid JSON received from player %d: %s\n", $player->id, $decoded_message);
                    }
                    // --- End Process Client Message ---

                    // Note: Broadcasting is now handled by the main loop timer, not here.
                } else {
                    // Handle invalid frame (optional: disconnect player)
                    printf("Received invalid/control frame from %s (ID: %d).\n", $player->peerName, $player->id);
                    // Consider disconnecting if invalid frames persist
                }
            }
        } // End foreach read_streams
    } // End if ($num_changed_streams > 0)

    // Small sleep to prevent busy-waiting if loop finishes faster than tick rate
    // This might not be strictly necessary with stream_select timeout, but can help CPU usage.
    $elapsed = microtime(true) - $currentTime;
    $sleepTime = SERVER_TICK_INTERVAL_US - (int)($elapsed * 1_000_000);
    if ($sleepTime > 0) {
        usleep($sleepTime);
    }
} // End while(true)

// --- Server Shutdown ---
// Close all player connections first
foreach ($players as $player) {
    printf("Closing connection for player %s\n", $player->peerName);
    fclose($player->stream);
}
// Close the master server socket
printf("Closing server socket.\n");
fclose($server_socket);

// --- Helper Functions ---

/**
 * Generates the initial game map.
 *
 * @param int $width The width of the map.
 * @param int $height The height of the map.
 * @return array<int, array<int, Tile>> The generated map.
 */
function generateMap(int $width, int $height): array {
    if ($width % 2 === 0 || $height % 2 === 0) {
        fprintf(STDERR, "Warning: Map width and height should ideally be odd for standard Bomberman layout.\n");
    }

    $map = [];

    for ($y = 0; $y < $height; $y++) {
        $map[$y] = [];
        for ($x = 0; $x < $width; $x++) {
            if ($y === 0 || $y === $height - 1 || $x === 0 || $x === $width - 1) {
                // Outer border walls
                $map[$y][$x] = Tile::Wall;
            } elseif ($y % 2 === 0 && $x % 2 === 0) {
                // Inner indestructible walls (pillars)
                $map[$y][$x] = Tile::Wall;
            } else {
                // Potential locations for breakable walls or grass
                // Avoid placing breakable walls right next to typical starting positions (optional)
                $isStartPositionArea = ($y <= 2 && $x <= 2) || ($y <= 2 && $x >= $width - 3) ||
                    ($y >= $height - 3 && $x <= 2) || ($y >= $height - 3 && $x >= $width - 3);

                if (!$isStartPositionArea && mt_rand() / mt_getrandmax() < BREAKABLE_WALL_DENSITY) {
                    $map[$y][$x] = Tile::BreakableWall;
                } else {
                    $map[$y][$x] = Tile::Grass;
                }
            }
        }
    }
    // Ensure starting positions are clear
    $map[1][1] = Tile::Grass;
    $map[1][2] = Tile::Grass;
    $map[2][1] = Tile::Grass;


    printf("Generated %dx%d map.\n", $width, $height);
    // Optional: Print map to console for debugging
    printMapToConsole($map);

    return $map;
}

/**
 * (Optional) Helper function to print the map to the console for debugging.
 * @param array<int, array<int, Tile>> $map
 */
function printMapToConsole(array $map): void {
    echo "--- Game Map --- \n";
    foreach ($map as $row) {
        foreach ($row as $tile) {
            echo match ($tile) {
                Tile::Grass => '.',
                Tile::Wall => '#',
                Tile::BreakableWall => '%',
                default => '?',
            };
        }
        echo "\n";
    }
    echo "----------------\n";
}


function perform_handshake($client_stream, $header): bool {
    $headers = [];
    $lines = preg_split("/\r\n/", $header);
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (preg_match("/\A(\S+): (.*)\z/", $line, $matches)) {
            $headers[strtolower($matches[1])] = $matches[2];
        }
    }

    if (!isset($headers["sec-websocket-key"])) {
        fprintf(STDERR, "Missing Sec-WebSocket-Key header.\n");
        return false;
    }

    $sec_key = $headers["sec-websocket-key"];
    $magic_string = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
    $sec_accept = base64_encode(pack("H*", sha1($sec_key . $magic_string)));

    $upgrade_response = sprintf(
        "HTTP/1.1 101 Switching Protocols\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: %s\r\n\r\n",
        $sec_accept
    );

    if (fwrite($client_stream, $upgrade_response) === false) {
        fprintf(STDERR, "fwrite() failed during handshake.\n");
        return false;
    }
    return true;
}

// Basic frame decoding (only handles unmasked text frames)
function decode_frame(string $data): ?string {
    if (strlen($data) < 2) return null;

    $b1 = ord($data[0]);
    $b2 = ord($data[1]);

    $fin = ($b1 & 0x80) == 0x80;
    $opcode = $b1 & 0x0F;
    $masked = ($b2 & 0x80) == 0x80;
    $payload_len = $b2 & 0x7F;

    // Handle different opcodes if needed (e.g., 0x8 for close)
    if ($opcode === 0x8) { // Close frame
        fprintf(STDERR, "Received close frame (opcode 8).\n");
        // You might want to initiate the closing handshake here
        return null; // Or return a special value/object indicating close
    }

    if ($opcode !== 1) { // We only process text frames (opcode 1) further
        fprintf(STDERR, "Received non-text frame (opcode %d).\n", $opcode);
        return null;
    }
    if (!$masked) {
        fprintf(STDERR, "Received unmasked frame from client.\n");
        return null;
    }

    $mask_key_offset = 2;
    if ($payload_len == 126) {
        if (strlen($data) < 4) return null;
        $payload_len = unpack("n", substr($data, 2, 2))[1];
        $mask_key_offset = 4;
    } elseif ($payload_len == 127) {
        if (strlen($data) < 10) return null;
        list(, $higher, $lower) = unpack("N2", substr($data, 2, 8));
        $payload_len = $higher << 32 | $lower;
        if ($payload_len > 10 * 1024 * 1024) { // Limit payload size (e.g., 10MB)
            fprintf(STDERR, "Payload length too large: %d\n", $payload_len);
            return null;
        }
        $mask_key_offset = 10;
    }

    if (strlen($data) < $mask_key_offset + 4) return null;
    $mask_key = substr($data, $mask_key_offset, 4);
    $payload_offset = $mask_key_offset + 4;

    // Check if we have received the complete payload
    if (strlen($data) < $payload_offset + $payload_len) {
        // fprintf(STDERR, "Incomplete frame received. Expected %d bytes payload, got %d bytes after header.\n", $payload_len, strlen($data) - $payload_offset);
        // This basic implementation doesn't handle fragmented messages or incomplete reads well.
        // A more robust solution would buffer incoming data.
        return null; // Treat incomplete frame as error for now
    }
    $payload = substr($data, $payload_offset, $payload_len);

    $unmasked_payload = "";
    for ($i = 0; $i < $payload_len; $i++) {
        $unmasked_payload .= $payload[$i] ^ $mask_key[$i % 4];
    }

    if (!mb_check_encoding($unmasked_payload, "UTF-8")) {
        fprintf(STDERR, "Received invalid UTF-8 data.\n");
        return null;
    }

    return $unmasked_payload;
}

// Basic frame encoding (only text frames, unmasked)
function encode_frame(string $message): string {
    $len = strlen($message);
    $frame_header = "";

    $frame_header .= pack("C", 0x81); // FIN bit (1) + Text Opcode (1)

    if ($len <= 125) {
        $frame_header .= pack("C", $len); // Mask bit (0) + length
    } elseif ($len <= 65535) {
        $frame_header .= pack("C", 126); // Mask bit (0) + 126
        $frame_header .= pack("n", $len); // 16-bit length
    } else {
        $frame_header .= pack("C", 127); // Mask bit (0) + 127
        $frame_header .= pack("N", 0);   // 64-bit length (higher 32 bits)
        $frame_header .= pack("N", $len); // 64-bit length (lower 32 bits)
    }

    return $frame_header . $message;
}

/**
 * Handles player disconnection.
 * @param Player $player The player who disconnected.
 * @param array<int, Player> &$players Pass by reference to modify.
 * @param array<int, Player> &$playersByResourceId Pass by reference to modify.
 */
function handle_disconnect(Player $player, array &$players, array &$playersByResourceId): void {
    printf("Player disconnected: %s (ID: %d).\n", $player->peerName, $player->id);
    @fclose($player->stream); // Close the stream (@ suppresses errors if already closed)
    $playerId = $player->id;
    $resourceId = $player->getResourceId();
    unset($players[$playerId]);
    unset($playersByResourceId[$resourceId]);

    // Notify remaining players
    $disconnectMessage = json_encode(["type" => "playerRemove", "id" => $playerId]);
    if ($disconnectMessage) {
        // Use the global $playersByResourceId here or pass it if needed
        broadcast($GLOBALS['playersByResourceId'], $disconnectMessage); // Send to all remaining
    }
}

/**
 * Broadcasts a message to multiple players.
 *
 * @param array<int, Player> $recipients Associative array of Player objects keyed by resource ID.
 * @param string $message The JSON encoded message string to send.
 * @param ?int $excludeResourceId Optional resource ID to exclude from broadcast.
 */
function broadcast(array $recipients, string $message, ?int $excludeResourceId = null): void {
    if (empty($message)) return;
    // Optimization: Encode only if there are recipients
    if (empty($recipients)) return;

    $encodedMessage = null; // Encode lazily

    foreach ($recipients as $resourceId => $recipientPlayer) {
        if ($excludeResourceId !== null && $resourceId === $excludeResourceId) {
            continue; // Skip excluded player
        }

        // Encode the message only once, when the first valid recipient is found
        if ($encodedMessage === null) {
            $encodedMessage = encode_frame($message);
            if ($encodedMessage === false || $encodedMessage === "") {
                fprintf(STDERR, "Error encoding broadcast message: %s\n", $message);
                return; // Don't try to send invalid encoded message
            }
        }


        // Use the Player's send method which handles encoding (or send pre-encoded)
        // $recipientPlayer->send($message); // If send expects raw message
        // OR send pre-encoded frame directly:
        if (@fwrite($recipientPlayer->stream, $encodedMessage) === false) {
            fprintf(STDERR, "Failed broadcast to player %s (ID: %d)\n", $recipientPlayer->peerName, $recipientPlayer->id);
            // Optionally trigger disconnect for the failed recipient here
            // Be careful with modifying the array being iterated over.
            // Consider collecting failed players and handling them after the loop.
            // handle_disconnect($recipientPlayer, $GLOBALS['players'], $GLOBALS['playersByResourceId']); // Need access to global state or pass it in
        }
    }
}

// Add necessary POSIX constants if not defined (e.g., on Windows)
if (!defined('EINTR')) define('EINTR', 4); // Example value, check your system if needed
