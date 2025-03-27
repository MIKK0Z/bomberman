<?php
declare(strict_types=1);

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

class Game implements JsonSerializable {
    public static int $game_width = 31;
    public static int $game_height = 13;

    private Array $map = array();
    
    public function jsonSerialize() {
	return get_object_vars($this);
    }

    public function init_map() {
	for ($y = 0; $y < self::$game_height; $y++) {
	    $new_row = array();
	    for ($x = 0; $x < self::$game_width; $x++) {
		array_push($new_row, "a");
	    }
	    array_push($this -> map, $new_row);
	}	
    }
}

$game = new Game();
echo json_encode($game, JSON_PRETTY_PRINT);
echo "\n\n";

$game -> init_map();
echo json_encode($game, JSON_PRETTY_PRINT);
echo "\n";

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

    public function __toString() {
	return json_encode($this);
    }

    public function jsonSerialize() {
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


return;

echo "Server started at ws://" . $host . ":" . $port . "\n";

while (true) {
    $changed = $clients;
    stream_select($changed, $write, $except, 4); // 4s - TICKI!!!!!
 
	if (in_array($server, $changed)) {
        $client = @stream_socket_accept($server);
        if (!$client) {
            continue;
        }
        $clients[] = $client;
        $ip = stream_socket_get_name($client, true);
        echo "New Client connected from $ip\n";

        stream_set_blocking($client, true);
        $headers = fread($client, 1500);
        handshake($client, $headers, $host, $port);
        stream_set_blocking($client, false);

        $data=["msg" => "Nastąpiło połączenie"];

        send_message($clients, mask(json_encode($data))); //połączenie -> aktualne dane

        $found_socket = array_search($server, $changed);
        unset($changed[$found_socket]);
    }

    foreach ($changed as $changed_socket) {   // wiadomość od klienta
        print_r($changed_socket);
      
        $ip = stream_socket_get_name($changed_socket, true);
        $buffer = stream_get_contents($changed_socket);
      
        if ($buffer == false) {
            echo "Client Disconnected from $ip\n";
            @fclose($changed_socket);
            $found_socket = array_search($changed_socket, $clients);
            unset($clients[$found_socket]);
        }
      
        $unmasked = unmask($buffer);
        if ($unmasked != "") {
            echo "\nReceived a Message from $ip:\n\"$unmasked\" \n";
        }
	
	if (!is_closing_frame($buffer)) {
	    $response = mask($unmasked);
	    send_message($clients, $response);
	}
    }

    send_message($clients, mask(json_encode(["msg"=>"tick"])));
}
fclose($server);

function is_closing_frame($text) {
    $opcode = @ord($text[0]) & 0x0f;
    return $opcode == 8;
}

function unmask($text)
{
    $length = @ord($text[1]) & 127;
    if ($length == 126) {
        $masks = substr($text, 4, 4);
        $data = substr($text, 8);
    } elseif ($length == 127) {
        $masks = substr($text, 10, 4);
        $data = substr($text, 14);
    } else {
        $masks = substr($text, 2, 4);
        $data = substr($text, 6);
    }
    $text = "";
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i % 4];
    }
    return $text;
}

function mask($text)
{
    $b1 = 0x80 | (0x1 & 0x0f);
    $length = strlen($text);
    if ($length <= 125)
        $header = pack('CC', $b1, $length);
    elseif ($length > 125 && $length < 65536)
        $header = pack('CCn', $b1, 126, $length);
    elseif ($length >= 65536)
        $header = pack('CCNN', $b1, 127, $length);
    return $header . $text;
}

function handshake($client, $rcvd, $host, $port)
{
    $headers = array();
    $lines = preg_split("/\r\n/", $rcvd);
    foreach ($lines as $line) {
        $line = rtrim($line);
        if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
            $headers[$matches[1]] = $matches[2];
        }
    }
    $secKey = $headers['Sec-WebSocket-Key'];
    $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
    //hand shaking header
    $upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "WebSocket-Origin: $host\r\n" .
        "WebSocket-Location: ssl://$host:$port\r\n" .
        "Sec-WebSocket-Version: 13\r\n".
        "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
    fwrite($client, $upgrade);
}

function send_message($clients, $msg)
{
    foreach ($clients as $changed_socket) {
        @fwrite($changed_socket, $msg);
    }
}
