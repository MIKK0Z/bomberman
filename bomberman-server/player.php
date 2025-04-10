<?php
declare(strict_types=1);


class Player implements JsonSerializable {
    private mixed $ip;

    /**
     * @param mixed $ip
     */
    public function __construct(mixed $ip) {
        $this -> ip = $ip; 
    }

    public function jsonSerialize(): Array {
        return array();
    }
}

