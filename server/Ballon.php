<?php

declare(strict_types=1);

class Ballon implements JsonSerializable {
    private int $from_x;
    private int $from_y;

    private int $to_x;
    private int $to_y;

    public function __construct(int $from_x, int $from_y, int $to_x, int $to_y) {
        $this->from_x = $from_x;
        $this->from_y = $from_y;
        $this->to_x = $to_x;
        $this->to_y = $to_y;
    }

    public function getFromX(): int {
        return $this->from_x;
    }

    public function getFromY(): int {
        return $this->from_y;
    }

    public function getToX(): int {
        return $this->to_x;
    }

    public function getToY(): int {
        return $this->to_y;
    }

    public function setFromX(int $from_x): void {
        $this->from_x = $from_x;
    }

    public function setFromY(int $from_y): void {
        $this->from_y = $from_y;
    }

    public function setToX(int $to_x): void {
        $this->to_x = $to_x;
    }

    public function setToY(int $to_y): void {
        $this->to_y = $to_y;
    }

    public function jsonSerialize(): array {
        return array(
            "from_x" => $this->from_x,
            "from_y" => $this->from_y,
            "to_x" => $this->to_x,
            "to_y" => $this->to_y,
        );
    }
}
