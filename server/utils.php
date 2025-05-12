<?php

declare(strict_types=1);

/**
 * Generates the game map.
 *
 * @param array<array<Tile>> &$map The map array to modify (passed by reference).
 * @return void
 */
function generate_map(array &$map): void {
    $map = array();

    for ($y = 0; $y < GAME_HEIGHT; $y++) {
        $map[$y] = array();
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

/**
 * Extracts the client sockets from an array of Player objects.
 * 
 * @param array<Player> $players An array of Player objects.
 * @return array<rescource> An array of client sockets.
 */
function get_clients(array $players): array {
    $clients = array();
    foreach ($players as $player) {
        array_push($clients, $player->getClient());
    }

    return $clients;
}

/**
 * Performs the actions to disconnect a player: unsets from array and notifies others.
 *
 * @param string $peer_name The peer name of the player to disconnect.
 * @param array<Player> &$players The array of players (passed by reference).
 * @return void
 */
function handle_player_disconnection(string $peer_name, array &$players): void {
    if (isset($players[$peer_name])) {
        unset($players[$peer_name]);

        ws_send(
            json_encode(array(
                "type" => "player_disconnected",
                "peer_name" => $peer_name,
            )),
            get_clients($players)
        );
    }
}
