<?php

declare(strict_types=1);

enum Tile: int {
    case Wall = 0;
    case BreakableWall = 1;
    case Grass = 2;
}
