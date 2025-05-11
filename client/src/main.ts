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
    const deltaTime = (currentTime - lastFrameTime) / 1000; // Convert to seconds
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


    requestAnimationFrame(gameLoop);
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
                    players = message.players;
                    player_speed = message.player_speed;
                    break;

                case "new_player":
                    console.info("Info: New player message", message);
                    players.push(message.player);
                    break;

                case "player_disconnect":
                    console.info("Info: Player disconnect message", message);
                    players = players.filter((player) => player.id !== message.player_id);
                    break;

                case "update":

                    break;

                default:
                    console.warn("Warn: Unknown message type", message);
            }
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



