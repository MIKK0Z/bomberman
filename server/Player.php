<?php

declare(strict_types=1);

require_once __DIR__ . "/Tile.php";

class Player implements JsonSerializable {
    /** @var resource stram socket */
    private $client;

    private readonly string $peerName;
    private readonly string $id;

    private float $pos_x;
    private float $pos_y;

    private int $vel_x;
    private int $vel_y;

    /**
     * Constructor for the Player class.
     * 
     * @param resource $client stream socket.
     */
    public function __construct($client) {
        $this->client = $client;
        $this->peerName = stream_socket_get_name($client, true);

        $this->id = uniqid();

        $this->pos_x = 1;
        $this->pos_y = 1;

        $this->vel_x = 0;
        $this->vel_y = 0;
    }

    public function __destruct() {
        if (is_resource($this->client)) {
            fclose($this->client);
            unset($this->client);
        }
    }

    /**
     * Get the client stream socket.
     * 
     * @return resource The client stream socket.
     */
    public function getClient() {
        return $this->client;
    }

    public function getPeerName(): string {
        return $this->peerName;
    }

    public function getId(): string {
        return $this->id;
    }

    public function getPosX(): float {
        return $this->pos_x;
    }

    public function setPosX(float $pos_x): void {
        $this->pos_x = $pos_x;
    }

    public function getPosY(): float {
        return $this->pos_y;
    }

    public function setPosY(float $pos_y): void {
        $this->pos_y = $pos_y;
    }

    public function getVelX(): int {
        return $this->vel_x;
    }

    public function setVelX(int $vel_x): void {
        $this->vel_x = $vel_x;
    }

    public function getVelY(): int {
        return $this->vel_y;
    }

    public function setVelY(int $vel_y): void {
        $this->vel_y = $vel_y;
    }

    /** 
     * Move the player based on velocity and delta time, checking for collisions.
     * 
     * @param float $delta_time The time delta since the last update.
     * @param array<array<Tile>> $map The game map for collision detection.
     * @return void
     */
    public function move(float $delta_time, array $map): void {
        $next_x = $this->pos_x + $this->vel_x * $delta_time;
        $next_y = $this->pos_y + $this->vel_y * $delta_time;

        // Check collision on X axis
        $check_x = $next_x;
        $check_y = $this->pos_y; // Use current Y for X-axis check

        $tile_x_index = floor($check_x + ($this->vel_x > 0 ? 1.0 : 0.0)); // Check tile in direction of movement
        $tile_y_index = floor($check_y + 0.0); // Check middle of player height

        if (
            isset($map[$tile_y_index][$tile_x_index]) &&
            ($map[$tile_y_index][$tile_x_index] === Tile::Wall || $map[$tile_y_index][$tile_x_index] === Tile::BreakableWall)
        ) {
            // Collision on X, don't update pos_x
        } else {
            $this->pos_x = $next_x;
        }

        // Check collision on Y axis
        $check_x = $this->pos_x; // Use potentially updated X for Y-axis check
        $check_y = $next_y;

        $tile_x_index = floor($check_x + 0.0); // Check middle of player width
        $tile_y_index = floor($check_y + ($this->vel_y > 0 ? 1.0 : 0.0)); // Check tile in direction of movement

        if (
            isset($map[$tile_y_index][$tile_x_index]) &&
            ($map[$tile_y_index][$tile_x_index] === Tile::Wall || $map[$tile_y_index][$tile_x_index] === Tile::BreakableWall)
        ) {
            // Collision on Y, don't update pos_y
        } else {
            $this->pos_y = $next_y;
        }

        // Optional: Clamp position to map boundaries (if needed, though walls should prevent this)
        // $this->pos_x = max(1.0, min(GAME_WIDTH - 2.0, $this->pos_x)); // Assuming 1-unit border walls
        // $this->pos_y = max(1.0, min(GAME_HEIGHT - 2.0, $this->pos_y));
    }

    public function jsonSerialize(): mixed {
        return array(
            "id" => $this->id,
            "pos_x" => $this->pos_x,
            "pos_y" => $this->pos_y,
            "vel_x" => $this->vel_x,
            "vel_y" => $this->vel_y,
        );
    }
}
