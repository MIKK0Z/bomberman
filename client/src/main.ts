import "modern-normalize/modern-normalize.css";

import "./style.css";
import sprite_sheet_src from "./assets/sprite_sheet.png"

const SPRITE_SIZE = 16;
const TILE_SIZE = 32;
let sprite_sheet: SpriteSheet | null = null;

const WS_HOST = import.meta.env.WS_SERVER_HOST ?? "127.0.0.1";
const WS_PORT = import.meta.env.WS_SERVER_PORT ?? "46089";

const WS_URL = `ws://${WS_HOST}:${WS_PORT}`;
let ws: WebSocket | null = null;

let pressedKeys: Set<string> = new Set();

const app = document.querySelector<HTMLDivElement>("#app")!;

const canvas = document.createElement("canvas");
const ctx = canvas.getContext("2d")!;
app.appendChild(canvas);

let lastFrameTime = performance.now();

enum Tile {
    Wall = 0,
    BreakableWall = 1,
    Grass = 2,
}

type Player = {
    id: string;
    pos_x: number;
    pos_y: number;
    vel_x: number;
    vel_y: number;
    // Add render positions for smooth interpolation
    render_pos_x: number;
    render_pos_y: number;
}
type Map = Array<Array<Tile>>;
type ServerMessage =
    | { type: "connection"; map_width: number; map_height: number, map: Map, players: Array<Player>, player_speed: number }
    | { type: "new_player"; player: Player }
    | { type: "player_disconnect"; player_id: string }
    | { type: "update", players: Array<Player> }

let players: Array<Player> = [];
let map: Map = [];
let player_speed = 1;

class SpriteSheet {
    private readonly sprite_sheet_src: string;

    private sprite_sheet: HTMLImageElement | null = null;
    private isLoaded: boolean = false;;

    private readonly sprite_size: number;
    private readonly tile_size: number;

    public constructor(sprite_sheet_src: string, sprite_size: number, tile_size: number) {
        this.sprite_sheet_src = sprite_sheet_src;
        this.sprite_size = sprite_size;
        this.tile_size = tile_size;
    }

    public async init(): Promise<void> {
        console.info("Info: Initializing sprite sheet...");
        return new Promise((resolve, reject) => {
            if (this.checkLoaded()) {
                console.warn("Warn: SpriteSheet already initialized");
                resolve()
            }

            this.sprite_sheet = new Image();
            this.sprite_sheet.src = this.sprite_sheet_src;
            this.sprite_sheet.onload = () => {
                console.info("Info: SpriteSheet initialized");
                this.isLoaded = true;
                resolve();
            }

            this.sprite_sheet.onerror = (error) => {
                console.error("Error: Failed to initialize sprite sheet", error);
                reject(error);
            }
        });
    }

    public checkLoaded(): boolean {
        return this.sprite_sheet !== null && this.isLoaded;
    }

    /**
     * Draws a sprite from the sprite sheet to the canvas.
     * 
     * @param ctx Context to draw on
     * @param source_x X position of the sprite in the sprite sheet
     * @param source_y Y position of the sprite in the sprite sheet
     * @param dest_x X position to draw the sprite on the canvas
     * @param dest_y Y position to draw the sprite on the canvas
     * @returns void
     */
    public drawSprite(ctx: CanvasRenderingContext2D, source_x: number, source_y: number, dest_x: number, dest_y: number): void {
        if (!this.checkLoaded()) {
            console.error("Error: SpriteSheet not loaded");
            return;
        }

        ctx.drawImage(
            this.sprite_sheet!,
            this.sprite_size * source_x, this.sprite_size * source_y,
            this.sprite_size, this.sprite_size,
            dest_x, dest_y,
            this.tile_size, this.tile_size
        );
    }
};

const initWebSocket = async (): Promise<void> => {
    console.info("Info: Initializing WebSocket...");
    return new Promise((resolve, reject) => {
        if (ws) {
            console.warn("Warn: WebSocket already initialized");
            resolve();
        }

        try {
            ws = new WebSocket(WS_URL);
            ws.onopen = () => {
                console.info("Info: WebSocket connected");
                resolve();
            }
            ws.onerror = (error) => {
                console.error("Error: WebSocket error", error);
                reject(error);
            }
            ws.onclose = (event) => {
                console.warn("Warn: WebSocket closed", event);
                ws = null;
            }
            ws.onmessage = (event) => {
                const message: ServerMessage = JSON.parse(event.data);
                switch (message.type) {
                    case "connection":
                        console.info("Info: Connection message", message);
                        canvas.width = message.map_width * TILE_SIZE;
                        canvas.height = message.map_height * TILE_SIZE;
                        map = message.map;
                        // Initialize players with render positions
                        players = message.players.map(p => ({
                            ...p,
                            render_pos_x: p.pos_x,
                            render_pos_y: p.pos_y,
                        }));
                        player_speed = message.player_speed;
                        break;

                    case "new_player":
                        console.info("Info: New player message", message);
                        // Add new player with initial render position
                        players.push({
                            ...message.player,
                            render_pos_x: message.player.pos_x,
                            render_pos_y: message.player.pos_y,
                        });
                        break;

                    case "player_disconnect":
                        console.info("Info: Player disconnect message", message);
                        players = players.filter((player) => player.id !== message.player_id);
                        break;

                    case "update":
                        // Updates now only contain velocity information, not position
                        message.players.forEach(updatedPlayer => {
                            const existingPlayer = players.find(p => p.id === updatedPlayer.id);
                            if (existingPlayer) {
                                // Only update velocity from server updates now
                                // Position will be calculated locally in the game loop
                                existingPlayer.vel_x = updatedPlayer.vel_x;
                                existingPlayer.vel_y = updatedPlayer.vel_y;
                            } else if (updatedPlayer.id && updatedPlayer.vel_x !== undefined && updatedPlayer.vel_y !== undefined) {
                                // Add if player joined between connection and first update
                                // Create a new player with default position but correct velocity
                                console.warn("Warn: Received update for unknown player", updatedPlayer.id);
                                players.push({
                                    id: updatedPlayer.id,
                                    vel_x: updatedPlayer.vel_x,
                                    vel_y: updatedPlayer.vel_y,
                                    pos_x: TILE_SIZE, // Default position
                                    pos_y: TILE_SIZE, // Default position
                                    render_pos_x: TILE_SIZE,
                                    render_pos_y: TILE_SIZE
                                });
                            }
                        });
                        break;

                    default:
                        console.warn("Warn: Unknown message type", message);
                }
            }
        } catch (error) {
            console.error("Error: Failed to initialize WebSocket", error);
            reject(error);
        }
    });
}

const gameLoop = () => {
    if (!sprite_sheet) {
        console.error("Error: SpriteSheet not initialized");
        return;
    }

    const currentTime = performance.now();
    const deltaTime = (currentTime - lastFrameTime) / 1000; // Convert to seconds
    lastFrameTime = currentTime;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Draw the map
    for (let y = 0; y < map.length; y++) {
        for (let x = 0; x < map[y].length; x++) {
            const tile = map[y][x];
            switch (tile) {
                case Tile.Wall:
                    sprite_sheet.drawSprite(ctx, 3, 3, x * TILE_SIZE, y * TILE_SIZE);
                    break;
                case Tile.BreakableWall:
                    sprite_sheet.drawSprite(ctx, 4, 3, x * TILE_SIZE, y * TILE_SIZE);
                    break;
                case Tile.Grass:
                    sprite_sheet.drawSprite(ctx, 0, 5, x * TILE_SIZE, y * TILE_SIZE);
                    break;
            }
        }
    }

    // Find our local player (first player in the array)
    const localPlayer = players.length > 0 ? players[0] : null;
    let localPlayerVelocityChanged = false;
    let previousVelX = localPlayer?.vel_x;
    let previousVelY = localPlayer?.vel_y;

    // Update and draw all players (including local and remote players)
    for (const player of players) {
        // For all players, apply movement calculations based on velocity
        // Implement continuous collision detection by breaking movement into smaller steps
        // This prevents "tunneling" through walls when moving fast or with large delta time
        const stepSize = 0.05; // 50ms steps for collision detection
        let remainingDelta = deltaTime;

        while (remainingDelta > 0) {
            // Calculate the current step's delta time
            const stepDelta = Math.min(stepSize, remainingDelta);
            remainingDelta -= stepDelta;

            // Calculate potential new positions based on velocity for this step
            const new_pos_x = player.pos_x + player.vel_x * stepDelta * player_speed;
            const new_pos_y = player.pos_y + player.vel_y * stepDelta * player_speed;

            // Handle horizontal and vertical movement separately to allow sliding along walls
            if (player.vel_x !== 0) {
                const result = checkHorizontalCollision(player, new_pos_x, map);
                if (result.canMove) {
                    player.pos_x = new_pos_x;
                } else if (result.snapPosition !== null) {
                    // Snap to edge of blocking tile
                    player.pos_x = result.snapPosition;

                    // If this is the local player and they hit a wall, stop their movement in that direction
                    if (localPlayer && player.id === localPlayer.id) {
                        player.vel_x = 0;
                        localPlayerVelocityChanged = true;
                    }
                }
            }

            if (player.vel_y !== 0) {
                const result = checkVerticalCollision(player, new_pos_y, map);
                if (result.canMove) {
                    player.pos_y = new_pos_y;
                } else if (result.snapPosition !== null) {
                    // Snap to edge of blocking tile
                    player.pos_y = result.snapPosition;

                    // If this is the local player and they hit a wall, stop their movement in that direction
                    if (localPlayer && player.id === localPlayer.id) {
                        player.vel_y = 0;
                        localPlayerVelocityChanged = true;
                    }
                }
            }
        }

        sprite_sheet.drawSprite(ctx, 0, 0, player.pos_x, player.pos_y);
    }

    // If the local player's velocity changed (due to key press or wall collision), send update to the server
    if (localPlayer && ws && ws.readyState === WebSocket.OPEN) {
        if (localPlayerVelocityChanged ||
            (localPlayer.vel_x !== previousVelX || localPlayer.vel_y !== previousVelY)) {
            ws.send(JSON.stringify({
                vel_x: localPlayer.vel_x,
                vel_y: localPlayer.vel_y
            }));
        }
    }

    requestAnimationFrame(gameLoop);
}

/**
 * Check if a player can move horizontally without colliding with walls
 * 
 * @param player The player to check
 * @param new_pos_x The new x position
 * @param map The game map
 * @returns Object with canMove flag and snapPosition if collision detected
 */
const checkHorizontalCollision = (player: Player, new_pos_x: number, map: Map): { canMove: boolean, snapPosition: number | null } => {
    // Get the tile coordinates from pixel coordinates
    const next_tile_x = Math.floor((new_pos_x + (player.vel_x > 0 ? TILE_SIZE - 1 : 0)) / TILE_SIZE);
    const top_tile_y = Math.floor(player.pos_y / TILE_SIZE);
    const bottom_tile_y = Math.floor((player.pos_y + TILE_SIZE - 1) / TILE_SIZE);

    // Check all tiles that the player would intersect with
    for (let check_y = top_tile_y; check_y <= bottom_tile_y; check_y++) {
        if (map[check_y] && map[check_y][next_tile_x] !== undefined) {
            const tile = map[check_y][next_tile_x];
            if (tile === Tile.Wall || tile === Tile.BreakableWall) {
                // Collision detected - calculate position to snap to
                const snapPosition = player.vel_x > 0
                    ? next_tile_x * TILE_SIZE - TILE_SIZE  // Snap to left edge of wall when moving right
                    : (next_tile_x + 1) * TILE_SIZE;       // Snap to right edge of wall when moving left
                return { canMove: false, snapPosition };
            }
        }
    }

    return { canMove: true, snapPosition: null };
}

/**
 * Check if a player can move vertically without colliding with walls
 * 
 * @param player The player to check
 * @param new_pos_y The new y position
 * @param map The game map
 * @returns Object with canMove flag and snapPosition if collision detected
 */
const checkVerticalCollision = (player: Player, new_pos_y: number, map: Map): { canMove: boolean, snapPosition: number | null } => {
    // Get the tile coordinates from pixel coordinates
    const next_tile_y = Math.floor((new_pos_y + (player.vel_y > 0 ? TILE_SIZE - 1 : 0)) / TILE_SIZE);
    const left_tile_x = Math.floor(player.pos_x / TILE_SIZE);
    const right_tile_x = Math.floor((player.pos_x + TILE_SIZE - 1) / TILE_SIZE);

    // Check all tiles that the player would intersect with
    for (let check_x = left_tile_x; check_x <= right_tile_x; check_x++) {
        if (map[next_tile_y] && map[next_tile_y][check_x] !== undefined) {
            const tile = map[next_tile_y][check_x];
            if (tile === Tile.Wall || tile === Tile.BreakableWall) {
                // Collision detected - calculate position to snap to
                const snapPosition = player.vel_y > 0
                    ? next_tile_y * TILE_SIZE - TILE_SIZE  // Snap to top edge of wall when moving down
                    : (next_tile_y + 1) * TILE_SIZE;       // Snap to bottom edge of wall when moving up
                return { canMove: false, snapPosition };
            }
        }
    }

    return { canMove: true, snapPosition: null };
}

const initGame = async () => {
    console.info("Info: Initializing game...");
    document.addEventListener("keydown", (event) => {
        if (!event.key.startsWith("Arrow") || event.repeat) {
            return;
        }
        pressedKeys.add(event.key.slice(5));

        if (ws) ws.send(JSON.stringify(parsePresedKeys(pressedKeys)));
    });
    document.addEventListener("keyup", (event) => {
        if (!event.key.startsWith("Arrow") || event.repeat) {
            return;
        }
        pressedKeys.delete(event.key.slice(5));

        if (ws) ws.send(JSON.stringify(parsePresedKeys(pressedKeys)));
    });

    try {
        sprite_sheet = new SpriteSheet(sprite_sheet_src, SPRITE_SIZE, TILE_SIZE);
        await sprite_sheet.init();
        await initWebSocket();

        console.info("Info: Game initialized");
        gameLoop();
    } catch (error) {
        console.error("Error: Failed to initialize game", error);
    }
}

const parsePresedKeys = (pressedKeys: Set<string>): { vel_x: number, vel_y: number } => {
    let vel_x = 0;
    if (pressedKeys.has("Left")) {
        vel_x += -1;
    }
    if (pressedKeys.has("Right")) {
        vel_x += 1;
    }

    let vel_y = 0;
    if (pressedKeys.has("Up")) {
        vel_y += -1;
    }
    if (pressedKeys.has("Down")) {
        vel_y += 1;
    }

    return { vel_x, vel_y };
}

initGame()
