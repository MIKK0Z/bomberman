<?php
declare(strict_types=1);

const HOST = "localhost";
const PORT = 46089;
const TRANSPORT = "http";

const GAME_WIDTH = 31;
const GAME_HEIGHT = 13;

const TICK_LENGTH = 1;

class Player implements JsonSerializable {
    private mixed $client;
    private string $ip;



    public function jsonSerialize(): Array {
        return array();
    }
}

