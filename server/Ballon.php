<?php

declare(strict_types=1);

class Ballon implements JsonSerializable {
    private float $pos_x;
    private float $pos_y;

    private int $vel_x;
    private int $vel_y;

    public function __construct(float $pos_x, float $pos_y, int $vel_x, int $vel_y) {
        $this->pos_x = $pos_x;
        $this->pos_y = $pos_y;
        $this->vel_x = $vel_x;
        $this->vel_y = $vel_y;
    }

    public function getPosX(): float {
        return $this->pos_x;
    }

    public function getPosY(): float {
        return $this->pos_y;
    }

    public function getVelX(): int {
        return $this->vel_x;
    }

    public function getVelY(): int {
        return $this->vel_y;
    }

    public function setPosX(float $pos_x): void {
        $this->pos_x = $pos_x;
    }

    public function setPosY(float $pos_y): void {
        $this->pos_y = $pos_y;
    }

    public function setVelX(int $vel_x): void {
        $this->vel_x = $vel_x;
    }

    public function setVelY(int $vel_y): void {
        $this->vel_y = $vel_y;
    }

    public function jsonSerialize(): array {
        return array(
            "pos_x" => $this->pos_x,
            "pos_y" => $this->pos_y,
            "vel_x" => $this->vel_x,
            "vel_y" => $this->vel_y,
        );
    }
}
