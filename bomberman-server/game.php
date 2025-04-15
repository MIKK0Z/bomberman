<?php
declare(strict_types=1);

require("./player.php");

class Game implements JsonSerializable {
    private array $map = array();

    public function get_map(): Array {
        return $this -> map;
    }

    private array $players = array();

    private int $game_width;
    private int $game_height;

    public function __construct(int $game_width, int $game_height) {
        $this -> game_width = $game_width;
        $this -> game_height = $game_height;
    }

    public function jsonSerialize(): Array {
        return [
            "map" => $this -> map,
        ];
    }

    public function generate_map(): void {
	    for ($y = 0; $y < $this -> game_height; $y++) {
	        $new_row = array();
	        for ($x = 0; $x < $this -> game_width; $x++) {
                if ($y == 0 || $y == $this -> game_height - 1 || $x == 0 || $x == $this -> game_width - 1 || ($x % 2 == 0 && $y % 2 == 0)) {
                    array_push($new_row, Tiles::Barrier);
                } elseif (rand(0, 100) < 25) {
                    array_push($new_row, Tiles::BreakableBarrier);
                } else {
                    array_push($new_row, Tiles::Grass);
                }
            }
	        array_push($this -> map, $new_row);
	    }
    }

    public function add_player(mixed $client): void {
        $new_player = new Player($client);
        array_push($this -> players, $new_player);
    }

    public function get_players(): array {
        return $this -> players;
    }
}

enum Tiles: string {
    case Barrier = "B";
    case BreakableBarrier = "C";
    case Grass = "G";
}
