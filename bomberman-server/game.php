<?php
declare(strict_types=1);

class Game implements JsonSerializable {
    private Array $map = array();

    public function get_map(): Array {
        return $this -> map;
    }

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
}

enum Tiles: string {
    case Barrier = "B";
    case BreakableBarrier = "C";
    case Grass = "G";
}
