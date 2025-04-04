<?php
declare(strict_types=1);

class Server {
    private static $tick_length = 1; // in s

    private string $host = "";
    private int $port = -1;
    private string $transport = "";

    private $server;
    private Array $clients;
    private $write = NULL;
    private $except = NULL;

    private $mainloop;

    /**
     * @param callable(): string $mainloop
     */
    public function __construct(callable $mainloop, string $host = "localhost", int $port = 46089, string $transport = "http") {
	    $this -> mainloop = $mainloop;
	    $this -> host = $host;
	    $this -> port = $port;
    }

    public function init(): bool {
	    $this -> server = stream_socket_server("tcp://" . $this -> host . ":" . $this -> port, $errno, $errstr);
	    if (!$this -> server) {
	        echo $errstr . " (". $errno . ")\n";
	        return false;
	    }

	    $this -> clients = array($this -> server);
	    return true;
    }

    public function start(): void {
	    echo "Server started at ws://" . $this -> host . ":" . $this -> port . "\n";
	
	    while (true) {
	        $changed = $this -> clients;
	        stream_select($changed, $this -> write, $this -> except, self::$tick_length);

	        if (in_array($this -> server, $changed)) {
		        $client = @stream_socket_accept($this -> server);
		        if (!$client) {
		            continue;
		        }

		        array_push($this -> clients, $client);
		        $ip = stream_socket_get_name($client, true);
		        echo "New Client connected from $ip\n";

		        stream_set_blocking($client, true);
		        $headers = fread($client, 1500);
		        $this -> handshake($client, $headers, $this -> host, $this -> port);
		        stream_set_blocking($client, false);

		        $data=["msg" => "Nastąpiło połączenie"];
		        $this -> send_message($this -> clients, $this -> mask(json_encode($data)));

		        $found_socket = array_search($this -> server, $changed);
		        unset($changed[$found_socket]);
	        }

	        foreach ($changed as $changed_socket) {
		        print_r($changed_socket);

		        $ip = stream_socket_get_name($changed_socket, true);
		        $buffer = stream_get_contents($changed_socket);

		        if ($buffer == false) {
		            echo "Client Disconnected from $ip\n";
		            @fclose($changed_socket);
		            $found_socket = array_search($changed_socket, $this -> clients);
		            unset($this -> clients[$found_socket]);
                }

		        if (!$this -> is_closing_frame($buffer)) {
                    $unmasked = $this -> unmask($buffer);
		            if ($unmasked != "") {
		                echo "\nReceived a Message from $ip:\n\"$unmasked\" \n";
		            }
		
		            $this -> send_message($this -> clients, $buffer);
		        }
	        }

	        $this -> send_message($this -> clients, $this -> mask(json_encode(($this -> mainloop)("args"))));
	    }
	    
        fclose($this -> server);
    }
    /**
     * @param mixed $clients
     * @param mixed $msg
     */
    public function send_message($clients, $msg): void {
	    foreach ($clients as $changed_socket) {
	        @fwrite($changed_socket, $msg);
	    }
    }
    /**
     * @param mixed $text
     */
    private function is_closing_frame($text): bool {
	    $opcode = @ord($text[0]) & 0x0f;
	    return $opcode == 8;
    }
    /**
     * @param mixed $text
     */
    private function unmask($text): string {
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
    /**
     * @param mixed $text
     */
    private function mask($text): string {
	    $b1 = 0x80 | (0x1 & 0x0f);
	    $length = strlen($text);
	    if ($length <= 125) {
	        $header = pack("CC", $b1, $length);
	    } elseif ($length > 125 && $length < 65536) {
	        $header = pack("CCn", $b1, 126, $length);
	    } elseif ($length >= 65536) {
	        $header = pack("CCNN", $b1, 127, $length);
	    }
	    return $header . $text;
    }
    /**
     * @param mixed $client
     * @param mixed $rcvd
     * @param mixed $host
     * @param mixed $port
     */
    private function handshake($client, $rcvd, $host, $port): void {
	    $headers = array();
	    $lines = preg_split("/\r\n/", $rcvd);
        foreach ($lines as $line) {
	        $line = rtrim($line);
	        if (preg_match("/\A(\S+): (.*)\z/", $line, $matches)) {
		        $headers[$matches[1]] = $matches[2];
	        }
	    }
	    $secKey = $headers["Sec-WebSocket-Key"];
	    $secAccept = base64_encode(pack("H*", sha1($secKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11")));
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
}

