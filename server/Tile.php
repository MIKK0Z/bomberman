<?php

declare(strict_types=1);

/**
 * Represents the type of a tile on the game map.
 */
enum Tile: int {
    case Grass = 0;
    case Wall = 1;          // Indestructible wall
    case BreakableWall = 2; // Destructible wall
}
