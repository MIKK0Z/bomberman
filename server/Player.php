<?php

declare(strict_types=1);

// Add Direction enum (or use strings)
enum Direction: string {
    case Down = 'down';
    case Left = 'left';
    case Right = 'right';
    case Up = 'up';
}

class Player {
    /** @var resource The client's stream socket resource. */
    public $stream;
    /** @var string The peer name (IP:Port) of the client. */
    public string $peerName;
    /** @var int Unique player ID */
    public int $id;
    /** @var float Player X position (tile units) */
    public float $x;
    /** @var float Player Y position (tile units) */
    public float $y;
    /** @var float Horizontal velocity (-1, 0, 1) */
    public int $vx = 0;
    /** @var float Vertical velocity (-1, 0, 1) */
    public int $vy = 0;
    /** @var float Movement speed (tiles per second) */
    public float $speed = 3.0; // Adjust as needed
    /** @var Direction Current facing direction */
    public Direction $direction = Direction::Down;
    /** @var int Current animation frame (0, 1, 2) */
    public int $animationFrame = 0;
    /** @var float Timer for animation frame updates */
    private float $animationTimer = 0.0;
    /** @var float Time of the last update */
    public float $lastUpdateTime;
    /** @var array<string, bool> Tracks which keys are currently pressed */
    public array $keysPressed = [
        'ArrowUp' => false,
        'ArrowDown' => false,
        'ArrowLeft' => false,
        'ArrowRight' => false,
    ];

    /** @var bool Player alive status */
    public bool $isAlive = true;
    /** @var int Current death animation frame (0 to 6) */
    private int $deathAnimationFrame = 0;
    /** @var float Timer for death animation frame updates */
    private float $deathAnimationTimer = 0.0;
    /** @var float Duration of each death animation frame */
    private const DEATH_FRAME_DURATION = 0.15;

    /**
     * Constructor for the Player class.
     *
     * @param resource $clientStream The stream resource associated with the client connection.
     * @param string $peerName The peer name (IP:Port) obtained from stream_socket_accept.
     * @param int $id Unique player ID.
     * @param float $startX Initial X position.
     * @param float $startY Initial Y position.
     */
    public function __construct($clientStream, string $peerName, int $id, float $startX, float $startY) {
        $this->stream = $clientStream;
        $this->peerName = $peerName;
        $this->id = $id;
        $this->x = $startX;
        $this->y = $startY;
        $this->lastUpdateTime = microtime(true);
        printf("Player object created for %s (ID: %d) at (%.2f, %.2f)\n", $this->peerName, $this->id, $this->x, $this->y);
    }

    /**
     * Updates player state based on key presses.
     */
    private function updateVelocity(): void {
        $this->vx = 0;
        $this->vy = 0;
        if ($this->keysPressed['ArrowLeft']) $this->vx = -1;
        if ($this->keysPressed['ArrowRight']) $this->vx = 1;
        if ($this->keysPressed['ArrowUp']) $this->vy = -1;
        if ($this->keysPressed['ArrowDown']) $this->vy = 1;

        // Prioritize last pressed direction for animation (simple approach)
        // A more robust approach might track last *changed* direction
        if ($this->vy > 0) $this->direction = Direction::Down;
        elseif ($this->vy < 0) $this->direction = Direction::Up;
        elseif ($this->vx > 0) $this->direction = Direction::Right;
        elseif ($this->vx < 0) $this->direction = Direction::Left;
        // If vx and vy are 0, direction remains unchanged
    }

    /**
     * Handles key press event from the client.
     * @param string $key The key identifier (e.g., "ArrowUp").
     */
    public function handleKeyPress(string $key): void {
        if (!$this->isAlive) return; // Ignore input if dead
        if (array_key_exists($key, $this->keysPressed)) {
            $this->keysPressed[$key] = true;
            $this->updateVelocity();
        }
    }

    /**
     * Handles key release event from the client.
     * @param string $key The key identifier (e.g., "ArrowUp").
     */
    public function handleKeyRelease(string $key): void {
        if (array_key_exists($key, $this->keysPressed)) {
            $this->keysPressed[$key] = false;
            $this->updateVelocity();
        }
    }

    /**
     * Updates the player's position based on velocity, delta time, and map collisions.
     * Checks collisions based on the player's bounding box edges.
     *
     * @param float $deltaTime Time elapsed since the last update in seconds.
     * @param array<int, array<int, Tile>> $map The game map for collision detection.
     */
    public function updatePosition(float $deltaTime, array $map): void {
        if (!$this->isAlive) {
            $this->animationFrame = 0; // Reset to standing frame when not moving
            $this->animationTimer = 0;
            return; // Don't update position if dead
        }

        if ($this->vx === 0 && $this->vy === 0) {
            $this->animationFrame = 0; // Reset to standing frame when not moving
            $this->animationTimer = 0;
            return; // Not moving
        }

        $moveDistance = $this->speed * $deltaTime;
        $newX = $this->x; // Start with current position
        $newY = $this->y;

        // Define player hitbox size (adjust as needed, slightly smaller than 1.0)
        $playerHalfWidth = 0.4;
        $playerHalfHeight = 0.4;

        // --- Check Horizontal Movement & Collision ---
        if ($this->vx !== 0) {
            $potentialX = $this->x + $this->vx * $moveDistance;
            $checkX = ($this->vx > 0) ? $potentialX + $playerHalfWidth : $potentialX - $playerHalfWidth; // Leading edge X
            $tileX = (int)floor($checkX);

            // Check collision at top and bottom corners of the leading edge
            $tileYTop = (int)floor($this->y - $playerHalfHeight);
            $tileYBottom = (int)floor($this->y + $playerHalfHeight);

            $collisionX = false;
            if (isset($map[$tileYTop][$tileX]) && ($map[$tileYTop][$tileX] === Tile::Wall || $map[$tileYTop][$tileX] === Tile::BreakableWall)) {
                $collisionX = true;
            }
            if (!$collisionX && isset($map[$tileYBottom][$tileX]) && ($map[$tileYBottom][$tileX] === Tile::Wall || $map[$tileYBottom][$tileX] === Tile::BreakableWall)) {
                $collisionX = true;
            }
            // Check out of bounds horizontally
            if (!isset($map[$tileYTop][$tileX]) || !isset($map[$tileYBottom][$tileX])) {
                $collisionX = true;
            }


            if (!$collisionX) {
                $newX = $potentialX; // Allow horizontal movement
            }
            // Simple response: stop horizontal movement on collision. Could add sliding later.
        }

        // --- Check Vertical Movement & Collision ---
        if ($this->vy !== 0) {
            $potentialY = $this->y + $this->vy * $moveDistance;
            $checkY = ($this->vy > 0) ? $potentialY + $playerHalfHeight : $potentialY - $playerHalfHeight; // Leading edge Y
            $tileY = (int)floor($checkY);

            // Check collision at left and right corners of the leading edge (using the potentially updated newX)
            $tileXLeft = (int)floor($newX - $playerHalfWidth); // Use newX here
            $tileXRight = (int)floor($newX + $playerHalfWidth); // Use newX here

            $collisionY = false;
            if (isset($map[$tileY][$tileXLeft]) && ($map[$tileY][$tileXLeft] === Tile::Wall || $map[$tileY][$tileXLeft] === Tile::BreakableWall)) {
                $collisionY = true;
            }
            if (!$collisionY && isset($map[$tileY][$tileXRight]) && ($map[$tileY][$tileXRight] === Tile::Wall || $map[$tileY][$tileXRight] === Tile::BreakableWall)) {
                $collisionY = true;
            }
            // Check out of bounds vertically
            if (!isset($map[$tileY][$tileXLeft]) || !isset($map[$tileY][$tileXRight])) {
                $collisionY = true;
            }

            if (!$collisionY) {
                $newY = $potentialY; // Allow vertical movement
            }
            // Simple response: stop vertical movement on collision.
        }
        // --- End Collision Detection ---

        // Update position
        $this->x = $newX;
        $this->y = $newY;


        // --- Update Animation Frame ---
        // Only animate if the player actually moved (or attempted to move)
        if ($this->vx !== 0 || $this->vy !== 0) {
            $this->animationTimer += $deltaTime;
            $animationSpeed = 0.15; // Time between frames in seconds
            if ($this->animationTimer >= $animationSpeed) {
                $this->animationFrame = ($this->animationFrame + 1) % 3; // Cycle through 3 frames (0, 1, 2)
                $this->animationTimer -= $animationSpeed; // Reset timer preserving overshoot
            }
        } else {
            // If movement stopped due to collision or key release, reset frame
            $this->animationFrame = 0;
            $this->animationTimer = 0;
        }
        // --- End Update Animation Frame ---
    }

    /**
     * Marks the player as dead and starts the death animation.
     */
    public function die(): void {
        if ($this->isAlive) {
            $this->isAlive = false;
            $this->vx = 0;
            $this->vy = 0;
            $this->deathAnimationFrame = 0;
            $this->deathAnimationTimer = 0.0;
            printf("Player %d died.\n", $this->id);
        }
    }

    /**
     * Updates the death animation frame based on delta time.
     *
     * @param float $deltaTime Time elapsed since the last update in seconds.
     */
    public function updateDeathAnimation(float $deltaTime): void {
        if ($this->isAlive || $this->deathAnimationFrame >= 7) {
            return; // Only run if dead and animation not finished
        }

        $this->deathAnimationTimer += $deltaTime;
        if ($this->deathAnimationTimer >= self::DEATH_FRAME_DURATION) {
            $this->deathAnimationFrame++;
            $this->deathAnimationTimer -= self::DEATH_FRAME_DURATION;
            if ($this->deathAnimationFrame >= 7) {
                printf("Player %d death animation finished.\n", $this->id);
                // Player remains dead, animation stops at the last frame (or could be hidden)
            }
        }
    }

    /**
     * Sends a WebSocket message to this player.
     * Assumes encode_frame() is available in the global scope.
     *
     * @param string $message The raw message string to send.
     * @return bool True on success, false on failure.
     */
    public function send(string $message): bool {
        if (!function_exists('encode_frame')) {
            fprintf(STDERR, "Error: encode_frame function not available for Player->send()\n");
            return false;
        }

        $encodedMessage = encode_frame($message);
        // Use stream_set_blocking temporarily for potentially better write reliability
        // stream_set_blocking($this->stream, true);
        $bytesWritten = @fwrite($this->stream, $encodedMessage); // Use @ to suppress potential warnings on broken pipe
        // stream_set_blocking($this->stream, false); // Set back to non-blocking

        if ($bytesWritten === false || $bytesWritten < strlen($encodedMessage)) {
            fprintf(STDERR, "Failed to send message completely to player %s (ID: %d)\n", $this->peerName, $this->id);
            // Consider triggering disconnection logic here
            return false;
        }
        return true;
    }

    /**
     * Gets the underlying stream resource ID.
     *
     * @return int The resource ID.
     */
    public function getResourceId(): int {
        return (int)$this->stream;
    }

    /**
     * Gets the player's state for broadcasting.
     *
     * @return array Serializable player state.
     */
    public function getState(): array {
        return [
            'id' => $this->id,
            'x' => round($this->x, 2), // Send rounded values
            'y' => round($this->y, 2),
            'direction' => $this->direction->value,
            'frame' => $this->animationFrame,
            'isAlive' => $this->isAlive,
            'deathFrame' => $this->deathAnimationFrame,
        ];
    }
}
