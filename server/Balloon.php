<?php

declare(strict_types=1);

require_once __DIR__ . '/Tile.php'; // Need Tile enum

class Balloon {
    public int $id;
    public float $x;
    public float $y;
    public int $vx = 0; // Horizontal velocity (-1, 0, 1)
    public int $vy = 0; // Vertical velocity (-1, 0, 1)
    public float $speed = 1.5; // Tiles per second (slower than player)
    public int $animationFrame = 0;
    private float $animationTimer = 0.0;
    private float $turnCheckTimer = 0.0; // Timer to decide when to check for turns
    private const TURN_CHECK_INTERVAL = 0.5; // Seconds between turn decisions at intersections
    private const TURN_PROBABILITY = 0.3; // 30% chance to turn at an intersection check

    public function __construct(int $id, float $startX, float $startY) {
        $this->id = $id;
        $this->x = $startX;
        $this->y = $startY;
        // Start moving in a random valid direction
        $this->findRandomInitialDirection();
        printf("Balloon object created (ID: %d) at (%.2f, %.2f)
", $this->id, $this->x, $this->y);
    }

    private function findRandomInitialDirection(): void {
        $directions = [[0, 1], [0, -1], [1, 0], [-1, 0]]; // Down, Up, Right, Left
        shuffle($directions);
        $this->vx = $directions[0][0];
        $this->vy = $directions[0][1];
    }

    /**
     * Checks if a given tile coordinate is walkable (Grass).
     * @param int $tileX
     * @param int $tileY
     * @param array<int, array<int, Tile>> $map
     * @return bool
     */
    private function isWalkable(int $tileX, int $tileY, array $map): bool {
        // Corrected the check from $map[$tileY][$tileY] to $map[$tileY][$tileX]
        return isset($map[$tileY][$tileX]) && $map[$tileY][$tileX] === Tile::Grass;
    }

    /**
     * Finds all valid directions (dx, dy) from the current position.
     * @param array<int, array<int, Tile>> $map
     * @return array<int, array<int, int>> List of valid [dx, dy] pairs.
     */
    private function getValidDirections(array $map): array {
        $validDirections = [];
        $currentTileX = (int)floor($this->x);
        $currentTileY = (int)floor($this->y);
        $potentialDirections = [[0, 1], [0, -1], [1, 0], [-1, 0]]; // Down, Up, Right, Left

        foreach ($potentialDirections as $dir) {
            $checkTileX = $currentTileX + $dir[0];
            $checkTileY = $currentTileY + $dir[1];
            if ($this->isWalkable($checkTileX, $checkTileY, $map)) {
                $validDirections[] = $dir;
            }
        }
        return $validDirections;
    }


    /**
     * Updates the balloon's position based on velocity, delta time, and map collisions.
     * Implements turning logic at intersections and dead ends.
     *
     * @param float $deltaTime Time elapsed since the last update in seconds.
     * @param array<int, array<int, Tile>> $map The game map for collision detection.
     */
    public function updatePosition(float $deltaTime, array $map): void {
        $moveDistance = $this->speed * $deltaTime;
        $potentialX = $this->x + $this->vx * $moveDistance;
        $potentialY = $this->y + $this->vy * $moveDistance;

        // Use simpler collision detection for balloons (center point)
        $nextTileX = (int)floor($this->x + $this->vx * 0.51); // Check slightly ahead in movement direction
        $nextTileY = (int)floor($this->y + $this->vy * 0.51);
        $currentTileX = (int)floor($this->x);
        $currentTileY = (int)floor($this->y);

        $collision = false;
        if (!isset($map[$nextTileY][$nextTileX]) || $map[$nextTileY][$nextTileX] !== Tile::Grass) {
            $collision = true;
        }

        // --- Turning Logic ---
        $this->turnCheckTimer += $deltaTime;
        $shouldCheckTurn = $this->turnCheckTimer >= self::TURN_CHECK_INTERVAL;

        if ($collision || $shouldCheckTurn) {
            $validDirections = $this->getValidDirections($map);
            $numValidDirections = count($validDirections);

            if ($collision) {
                // Forced turn: Hit a wall or obstacle
                // Remove the current direction if it led to collision (it should have)
                $currentDirIndex = -1;
                for ($i = 0; $i < count($validDirections); $i++) {
                    if ($validDirections[$i][0] == $this->vx && $validDirections[$i][1] == $this->vy) {
                        $currentDirIndex = $i;
                        break;
                    }
                }
                // If the direction causing collision is somehow still valid (e.g. corner case), remove it
                if ($currentDirIndex !== -1) {
                    // This case should ideally not happen if collision detection is right
                    // but as a safeguard, try removing the opposite direction if only one way back
                    // For simplicity now: just pick any *other* valid direction if possible
                }

                // Filter out the direction we came from if possible (avoid immediate 180 unless necessary)
                $possibleTurns = [];
                $reverseVx = -$this->vx;
                $reverseVy = -$this->vy;
                foreach ($validDirections as $dir) {
                    if ($numValidDirections <= 1 || ($dir[0] !== $reverseVx || $dir[1] !== $reverseVy)) {
                        $possibleTurns[] = $dir;
                    }
                }
                if (empty($possibleTurns)) { // Only option is back?
                    $possibleTurns = $validDirections; // Allow going back
                }


                if (!empty($possibleTurns)) {
                    $newDir = $possibleTurns[array_rand($possibleTurns)];
                    $this->vx = $newDir[0];
                    $this->vy = $newDir[1];
                    // Recalculate potential position after changing direction to avoid sticking
                    $potentialX = $this->x + $this->vx * $moveDistance;
                    $potentialY = $this->y + $this->vy * $moveDistance;
                } else {
                    // Trapped? Stop or reverse (simple reverse)
                    $this->vx = -$this->vx;
                    $this->vy = -$this->vy;
                    // Recalculate potential position
                    $potentialX = $this->x + $this->vx * $moveDistance;
                    $potentialY = $this->y + $this->vy * $moveDistance;
                }
                $this->turnCheckTimer = 0; // Reset check timer after forced turn

            } elseif ($shouldCheckTurn && $numValidDirections > 2) {
                // Optional turn: At an intersection (more than 2 valid directions)
                $this->turnCheckTimer = 0; // Reset check timer
                if ((mt_rand() / mt_getrandmax()) < self::TURN_PROBABILITY) {
                    // Decide to turn
                    // Filter out the reverse direction if possible
                    $possibleTurns = [];
                    $reverseVx = -$this->vx;
                    $reverseVy = -$this->vy;
                    foreach ($validDirections as $dir) {
                        if ($dir[0] !== $reverseVx || $dir[1] !== $reverseVy) {
                            $possibleTurns[] = $dir;
                        }
                    }
                    if (empty($possibleTurns)) { // Only option is back? (Shouldn't happen if numValid > 2)
                        $possibleTurns = $validDirections;
                    }

                    $newDir = $possibleTurns[array_rand($possibleTurns)];
                    $this->vx = $newDir[0];
                    $this->vy = $newDir[1];
                    // Recalculate potential position
                    $potentialX = $this->x + $this->vx * $moveDistance;
                    $potentialY = $this->y + $this->vy * $moveDistance;
                }
                // Else: continue straight even if at intersection
            }
        }
        // --- End Turning Logic ---


        // --- Update Position ---
        // Re-check collision slightly ahead with potentially new direction/potential position
        $finalNextTileX = (int)floor($potentialX + $this->vx * 0.1); // Smaller lookahead
        $finalNextTileY = (int)floor($potentialY + $this->vy * 0.1);

        if (isset($map[$finalNextTileY][$finalNextTileX]) && $map[$finalNextTileY][$finalNextTileX] === Tile::Grass) {
            $this->x = $potentialX;
            $this->y = $potentialY;
        } else {
            // If still colliding after turn logic, maybe just align to grid and stop briefly?
            // For now, just don't update position if the path is blocked.
            // This might cause slight sticking, refinement needed if it's an issue.
        }


        // --- Update Animation Frame ---
        $this->animationTimer += $deltaTime;
        $animationSpeed = 0.2; // Time between frames in seconds
        if ($this->animationTimer >= $animationSpeed) {
            $this->animationFrame = ($this->animationFrame + 1) % 3; // Cycle through 3 frames
            $this->animationTimer -= $animationSpeed;
        }
    }

    /**
     * Gets the balloon's state for broadcasting.
     * Determines direction based on velocity for client animation.
     *
     * @return array Serializable balloon state.
     */
    public function getState(): array {
        // Determine primary direction for animation (Down/Right vs Up/Left)
        $directionGroup = 'downRight'; // Default
        if ($this->vy < 0 || $this->vx < 0) { // Moving Up or Left
            $directionGroup = 'upLeft';
        }

        return [
            'id' => $this->id,
            'x' => round($this->x, 2),
            'y' => round($this->y, 2),
            'directionGroup' => $directionGroup, // 'upLeft' or 'downRight'
            'frame' => $this->animationFrame,
        ];
    }
}
