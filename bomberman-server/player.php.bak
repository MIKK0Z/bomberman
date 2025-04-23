<?php
declare(strict_types=1);

class Player implements JsonSerializable {
    private string $ip;
    private mixed $client;

    private bool $going_up = false;
    private bool $going_right = false;
    private bool $going_down = false;
    private bool $going_left = false;

    public function __construct(mixed $client) {
        $this -> client = $client;
        $this -> ip = stream_socket_get_name($client, true); 
    }

    public function jsonSerialize(): Array {
        return array();
    }

    public function set_going_up(bool $going_up): void {
        $this -> going_up = $going_up;
    }

    public function get_going_up(): bool {
        return $this -> going_up;
    }
    
    public function set_going_right(bool $going_right): void {
        $this -> going_right = $going_right;
    }

    public function get_going_right(): bool {
        return $this -> going_right;
    }

    public function set_going_down(bool $going_down): void {
        $this -> going_down = $going_down;
    }

    public function get_going_down(): bool {
        return $this -> going_down;
    }
    
    public function set_going_left(bool $going_left): void {
        $this -> going_left = $going_left;
    }

    public function get_going_left(): bool {
        return $this -> going_left;
    }
}

