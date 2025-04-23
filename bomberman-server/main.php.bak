<?php
declare(strict_types=1);

const HOST = "localhost";
const PORT = 46089;
const TRANSPORT = "http";

const GAME_WIDTH = 31;
const GAME_HEIGHT = 13;

require("./server.php");
require("./game.php");
require("./player.php");

$game = new Game(GAME_WIDTH, GAME_HEIGHT);
$game -> generate_map();

$mainloop = function(string $var): array {
    global $game;
    
    $state = array(
        "map" => $game -> get_map(),
        "players" => $game -> get_players(),
    );

    return $state;
};

$connect_new_player = function(mixed $client): void {
    $player = new Player($client);
};

$get_players = function(): array {
    global $game;

    return $game -> get_players();
};

$server = new Server($mainloop, $connect_new_player, $get_players, HOST, PORT, TRANSPORT);
if (!$server -> init()) {
   die("Server failed to initialize!"); 
}

$server -> start();

return;

$host = "localhost"; // moja domena w ct8.pl
$port = 46089; // zarezerwowany w panelu port
$transport = 'http';
$server = stream_socket_server("tcp://" . $host . ":" . $port, $errno, $errstr);
if (!$server) {
    die("$errstr ($errno)");
}
$clients = array($server); // tablica klientów
$write  = NULL;
$except = NULL;

enum Tiles: string {
    case Barrier = "X";
    case BreakaleBarrier = "#";
    case Grass = "*";
}

enum Direction: int {
    case Up = 0;
    case Right = 1;
    case Down = 2;
    case Left = 3;
}

class Entity implements JsonSerializable {
    public function __construct(int $x, int $y) {
        $this -> x = $x;
	    $this -> y = $y;
	    $this -> type = "entity";
    }

    public function __toString(): string {
	return json_encode($this);
    }

    public function jsonSerialize(): Array {
	return get_object_vars($this);
    }

    protected int $x = 0;
    protected int $y = 0;
    protected string $type = "";

    public function get_x(): int {
	return $this -> x;
    }

    public function set_x(int $x): void {
	$this -> x = $x;
    }

    public function get_y(): int {
	return $this -> y;
    }

    public function set_y(int $y): void {
	$this -> y = $y;
    }

    public function get_type(): string {
	return $this -> type;
    }

    protected function set_type(string $type): void {
	$this -> type = $type;
    }
}

class Barrier extends Entity {
    public function __construct(int $x, int $y) {
	parent::__construct($x, $y);
	$this -> set_type("barrier");
    }
}

class BreakableBarrier extends Entity {
    public function __construct(int $x, int $y) {
	parent::__construct($x, $y);
	$this -> set_type("breakable_barrier");
    }
}

class MovingEntity extends Entity {
    public function __construct(int $x, int $y, Direction $dir) {
	parent::__construct($x, $y);
	$this -> set_type("moving_entity");
	$this -> dir = $dir;
    }
    
    protected Direction $dir = Direction::Up;
    protected float $vel = 0;
    
    public function get_dir(): Direction {
	return $this -> dir;
    }

    public function set_dir(Direction $dir) {
	$this -> dir = $dir;
    }

    public function get_vel(): float {
	return $this -> vel; 
    }

    protected function set_vel(int $vel) {
	$this -> vel = $vel;
    }
}

class Ballon extends MovingEntity {
    public function __construct(int $x, int $y, Direction $dir) {
	parent::__construct($x, $y, $dir);
	$this -> set_type("ballon");
	$this -> set_vel(10);
    }    
}

