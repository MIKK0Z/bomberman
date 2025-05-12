import "modern-normalize/modern-normalize.css";

import "./style.css";

import sprite_sheet_src from "./assets/sprite_sheet.png"
import animations_data_json from "./data/animations.json";

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
let peer_name: string | null = null;

type AnimationFrameCoord = { x: number; y: number };
type AnimationSequence = Array<AnimationFrameCoord>;
interface AnimationsData {
    player: {
        frame_length: number;
        left: AnimationSequence;
        down: AnimationSequence;
        right: AnimationSequence;
        up: AnimationSequence;
        death: AnimationSequence;
    };
}

const animations_data: AnimationsData = animations_data_json;

enum Tile {
    Wall = 0,
    BreakableWall = 1,
    Grass = 2,
}

type ServerPlayer = {
    pos_x: number;
    pos_y: number;
    vel_x: number;
    vel_y: number;
}

type PlayerDirection = "left" | "right" | "up" | "down";
type PlayerActualAnimationName = Exclude<keyof AnimationsData["player"], "duration">;

type Player = ServerPlayer & {
    currentAnimationName: PlayerActualAnimationName;
    animationFrame: number;
    animationTimer: number;
    lastDirection: PlayerDirection;
};

type GameMap = Array<Array<Tile>>;
type ServerMessage =
    | { type: "connection"; map_width: number; map_height: number, map: GameMap, players: { [key: string]: ServerPlayer }, player_speed: number, peer_name: string }
    | { type: "new_player"; peer_name: string, player: ServerPlayer }
    | { type: "player_disconnected"; peer_name: string }
    | { type: "player_update", peer_name: string, player: ServerPlayer }

let players: { [key: string]: Player } = {};
let map: GameMap = [];
let player_speed = 1;

function isWallAt(tile_x: number, tile_y: number, map: GameMap): boolean {
    if (!map || map.length === 0 || !map[0] || map[0].length === 0) return true;
    if (tile_x < 0 || tile_x >= map[0].length || tile_y < 0 || tile_y >= map.length) return true;

    const tile = map[tile_y][tile_x];
    return tile === Tile.Wall || tile === Tile.BreakableWall;
}

function addAnimationDefaults(serverPlayer: ServerPlayer): Player {
    return {
        ...serverPlayer,
        currentAnimationName: "down",
        animationFrame: 0,
        animationTimer: 0,
        lastDirection: "down",
    };
}

class SpriteSheet {
    private readonly sprite_sheet_src: string;

    private sprite_sheet: HTMLImageElement | null = null;
    private isLoaded: boolean = false;

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
    const deltaTime = (currentTime - lastFrameTime) / 1000;
    lastFrameTime = currentTime;

    ctx.clearRect(0, 0, canvas.width, canvas.height);

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

    for (const [_peer_name, player] of Object.entries(players)) {
        const dx = player.vel_x * player_speed * deltaTime;
        const dy = player.vel_y * player_speed * deltaTime;

        let next_player_pos_x = player.pos_x + dx;
        if (dx > 0) {
            const y1_check = Math.floor(player.pos_y + 0.05);
            const y2_check = Math.floor(player.pos_y + 1 - 0.05);
            const collision_tile_x = Math.floor(next_player_pos_x + 1);

            if (isWallAt(collision_tile_x, y1_check, map) || isWallAt(collision_tile_x, y2_check, map)) {
                player.pos_x = collision_tile_x - 1;
            } else {
                player.pos_x = next_player_pos_x;
            }
        } else if (dx < 0) {
            const y1_check = Math.floor(player.pos_y + 0.05);
            const y2_check = Math.floor(player.pos_y + 1 - 0.05);
            const collision_tile_x = Math.floor(next_player_pos_x);

            if (isWallAt(collision_tile_x, y1_check, map) || isWallAt(collision_tile_x, y2_check, map)) {
                player.pos_x = collision_tile_x + 1;
            } else {
                player.pos_x = next_player_pos_x;
            }
        }

        let next_player_pos_y = player.pos_y + dy;
        if (dy > 0) {
            const x1_check = Math.floor(player.pos_x + 0.05);
            const x2_check = Math.floor(player.pos_x + 1 - 0.05);
            const collision_tile_y = Math.floor(next_player_pos_y + 1);

            if (isWallAt(x1_check, collision_tile_y, map) || isWallAt(x2_check, collision_tile_y, map)) {
                player.pos_y = collision_tile_y - 1;
            } else {
                player.pos_y = next_player_pos_y;
            }
        } else if (dy < 0) {
            const x1_check = Math.floor(player.pos_x + 0.05);
            const x2_check = Math.floor(player.pos_x + 1 - 0.05);
            const collision_tile_y = Math.floor(next_player_pos_y);

            if (isWallAt(x1_check, collision_tile_y, map) || isWallAt(x2_check, collision_tile_y, map)) {
                player.pos_y = collision_tile_y + 1;
            } else {
                player.pos_y = next_player_pos_y;
            }
        }

        let newAnimationRequired = false;
        let targetAnimationName: PlayerActualAnimationName = player.currentAnimationName;
        let newLastDirection: PlayerDirection = player.lastDirection;

        if (player.vel_x < 0) {
            targetAnimationName = "left";
            newLastDirection = "left";
        } else if (player.vel_x > 0) {
            targetAnimationName = "right";
            newLastDirection = "right";
        } else if (player.vel_y < 0) {
            targetAnimationName = "up";
            newLastDirection = "up";
        } else if (player.vel_y > 0) {
            targetAnimationName = "down";
            newLastDirection = "down";
        } else {
            targetAnimationName = player.lastDirection;
        }

        if (player.currentAnimationName !== targetAnimationName || player.lastDirection !== newLastDirection) {
            newAnimationRequired = true;
            player.currentAnimationName = targetAnimationName;
            player.animationFrame = 0;
            player.animationTimer = 0;
        }
        player.lastDirection = newLastDirection;

        player.animationTimer += deltaTime;

        const animationSequence = animations_data.player[player.currentAnimationName];
        const numFrames = animationSequence.length;
        const frameLength = animations_data.player.frame_length;

        if (player.vel_x !== 0 || player.vel_y !== 0) {
            if (player.animationTimer >= frameLength) {
                player.animationFrame = (player.animationFrame + 1) % numFrames;
                player.animationTimer %= frameLength;
            }
        } else {
            player.animationFrame = 0;
        }

        const spriteCoord = animationSequence[player.animationFrame];
        sprite_sheet.drawSprite(ctx, spriteCoord.x, spriteCoord.y, player.pos_x * TILE_SIZE, player.pos_y * TILE_SIZE);
    }

    requestAnimationFrame(gameLoop);
}

const initGame = async () => {
    console.info("Info: Initializing game...");
    document.addEventListener("keydown", (event) => {
        if (!event.key.startsWith("Arrow") || event.repeat) {
            return;
        }
        pressedKeys.add(event.key.slice(5));

        if (ws && peer_name) ws.send(JSON.stringify({ ...parsePresedKeys(pressedKeys), pos_x: players[peer_name].pos_x, pos_y: players[peer_name].pos_y }));
    });
    document.addEventListener("keyup", (event) => {
        if (!event.key.startsWith("Arrow") || event.repeat) {
            return;
        }
        pressedKeys.delete(event.key.slice(5));

        if (ws && peer_name) ws.send(JSON.stringify({ ...parsePresedKeys(pressedKeys), pos_x: players[peer_name].pos_x, pos_y: players[peer_name].pos_y }));
    });

    try {
        sprite_sheet = new SpriteSheet(sprite_sheet_src, SPRITE_SIZE, TILE_SIZE);
        await sprite_sheet.init();
        await initWebSocket();

        if (!ws) return; // Should never happen

        ws.onerror = (error) => {
            console.error("Error: WebSocket error", error);
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

                    const clientPlayers: { [key: string]: Player } = {};
                    for (const pName in message.players) {
                        clientPlayers[pName] = addAnimationDefaults(message.players[pName]);
                    }
                    players = clientPlayers;
                    player_speed = message.player_speed;
                    peer_name = message.peer_name;
                    break;

                case "new_player":
                    console.info("Info: New player message", message);
                    players[message.peer_name] = addAnimationDefaults(message.player);
                    break;

                case "player_disconnected":
                    console.info("Info: Player disconnect message", message);
                    delete players[message.peer_name];
                    break;

                case "player_update":
                    console.info("Info: Player update message", message);
                    const playerToUpdate = players[message.peer_name];
                    if (playerToUpdate) {
                        // Update core properties from server
                        playerToUpdate.pos_x = message.player.pos_x;
                        playerToUpdate.pos_y = message.player.pos_y;
                        playerToUpdate.vel_x = message.player.vel_x;
                        playerToUpdate.vel_y = message.player.vel_y;
                        // Animation properties (currentAnimationName, animationFrame, animationTimer, lastDirection)
                        // are managed client-side and will be updated by the gameLoop based on new vel_x/vel_y.
                    } else {
                        // Player doesn't exist locally, augment and add (should ideally be handled by "new_player")
                        players[message.peer_name] = addAnimationDefaults(message.player);
                    }
                    break;

                default:
                    console.warn("Warn: Unknown message type", message);
            }

            console.log("Info: Players", players);
        }

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



