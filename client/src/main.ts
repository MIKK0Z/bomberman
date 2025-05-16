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

let player_speed = 1;
let tick_length = 1;

type AnimationFrameCoord = { x: number; y: number };
type AnimationSequence = Array<AnimationFrameCoord>;
type AnimationsData = {
    player: {
        frame_length: number;
        left: AnimationSequence;
        down: AnimationSequence;
        right: AnimationSequence;
        up: AnimationSequence;
        death: AnimationSequence;
    }
    ballon: {
        frame_length: number;
        left: AnimationSequence;
        down: AnimationSequence;
        right: AnimationSequence;
        up: AnimationSequence;
    }
}

const animations_data: AnimationsData = animations_data_json;

enum Tile {
    Wall = 0,
    BreakableWall = 1,
    Grass = 2,
}

enum State {
    Alive = 0,
    Dying = 1,
    Dead = 2,
}

let game_state: State = State.Alive;

type Direction = "left" | "right" | "up" | "down";

type ServerPlayer = {
    pos_x: number;
    pos_y: number;
    vel_x: number;
    vel_y: number;
}
type PlayerAnimationName = Exclude<keyof AnimationsData["player"], "frame_length">;
type Player = ServerPlayer & {
    currentAnimationName: PlayerAnimationName;
    animationFrame: number;
    animationTimer: number;
    lastDirection: Direction;
    state: State;
};

type ServerBalloon = {
    from_x: number;
    from_y: number;
    to_x: number;
    to_y: number;
};
type BallonAnimationName = Exclude<keyof AnimationsData["ballon"], "frame_length">;
type Balloon = ServerBalloon & {
    current_x: number;
    current_y: number;
    interpolationTimer: number;

    currentAnimationName: BallonAnimationName;
    animationFrame: number;
    animationTimer: number;
};

type GameMap = Array<Array<Tile>>;
type ServerMessage =
    | { type: "connection"; map_width: number; map_height: number, map: GameMap, players: { [key: string]: ServerPlayer }, ballons: ServerBalloon[], accumulated_time: number, player_speed: number, tick_length: number, peer_name: string }
    | { type: "new_player"; peer_name: string, player: ServerPlayer }
    | { type: "player_disconnected"; peer_name: string }
    | { type: "player_update", peer_name: string, player: ServerPlayer }
    | { type: "game_update", ballons: Array<ServerBalloon> }
    | { type: "player_died", peer_name: string }

let players: { [key: string]: Player } = {};
let map: GameMap = [];
let balloons: Array<Balloon> = [];

// let game_state: GameState = GameState.Playing;

let voices = [];

function populateVoiceList() {
    let utterance = new SpeechSynthesisUtterance("Witaj świecie!");
    const synth = window.speechSynthesis;
    voices = synth.getVoices();
    console.log(voices);
    // "Chrome, at least, used to permit speech by default without user interaction, but that changed"
    // dodałem buttona by to przetestować
    speak();
};

function speak() {
    let utterance = new SpeechSynthesisUtterance("Hello world!");
    utterance.voice = voices[0]; // u mnie Zosia
    utterance.pitch = 1; // 0-2 (1 default)
    utterance.rate = 1; // 1 default, 0.5 - o połowę wolniej, 2 - dwa razy szybciej, itd.

    console.log(utterance)
    speechSynthesis.speak(utterance);
}

// chrome/safari - onvoicechanged, firefox - nie
// populateVoiceList();
// speak();
// setInterval(() => {
//     speak();
// }, 5000);

if (speechSynthesis.onvoiceschanged !== undefined) {
    speechSynthesis.onvoiceschanged = populateVoiceList;
}


function isWallAt(tile_x: number, tile_y: number, map: GameMap): boolean {
    if (!map || map.length === 0 || !map[0] || map[0].length === 0) return true;
    if (tile_x < 0 || tile_x >= map[0].length || tile_y < 0 || tile_y >= map.length) return true;

    const tile = map[tile_y][tile_x];
    return tile === Tile.Wall || tile === Tile.BreakableWall;
}

function addPlayerAnimationDefaults(serverData: ServerPlayer): Player {
    return {
        ...serverData,
        currentAnimationName: "down",
        animationFrame: 0,
        animationTimer: 0,
        lastDirection: "down",
        state: State.Alive,
    };
}

function addBalloonAnimationDefaults(serverData: ServerBalloon, animation_start_offset_time: number = 0): Balloon {
    let initialAnimationName: BallonAnimationName = "down";
    const dx = serverData.to_x - serverData.from_x;
    const dy = serverData.to_y - serverData.from_y;

    if (dx < 0) initialAnimationName = "left";
    else if (dx > 0) initialAnimationName = "right";
    else if (dy < 0) initialAnimationName = "up";
    else if (dy > 0) initialAnimationName = "down";

    let initial_current_x = serverData.from_x;
    let initial_current_y = serverData.from_y;
    let initial_interpolation_timer = 0;

    if (animation_start_offset_time > 0 && tick_length > 0) {
        const t_initial = Math.min(animation_start_offset_time, tick_length) / tick_length;
        initial_current_x = serverData.from_x + (serverData.to_x - serverData.from_x) * Math.min(t_initial, 1.0);
        initial_current_y = serverData.from_y + (serverData.to_y - serverData.from_y) * Math.min(t_initial, 1.0);
        initial_interpolation_timer = animation_start_offset_time;
    }

    return {
        ...serverData,
        current_x: initial_current_x,
        current_y: initial_current_y,
        interpolationTimer: initial_interpolation_timer,

        currentAnimationName: initialAnimationName,
        animationFrame: 0,
        animationTimer: animation_start_offset_time,
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

    if (game_state === State.Dead) {
        window.location.reload();
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

    balloons.forEach((ballon) => {
        if (!sprite_sheet) {
            console.error("Error: SpriteSheet not initialized");
            return;
        };

        ballon.interpolationTimer += deltaTime;
        const currentSegmentTime = Math.min(ballon.interpolationTimer, tick_length);

        let t = 0;
        if (tick_length > 0) {
            t = currentSegmentTime / tick_length;
        }
        t = Math.min(t, 1.0);

        ballon.current_x = ballon.from_x + (ballon.to_x - ballon.from_x) * t;
        ballon.current_y = ballon.from_y + (ballon.to_y - ballon.from_y) * t;

        let targetAnimationName: BallonAnimationName = ballon.currentAnimationName;
        const dx = ballon.to_x - ballon.from_x;
        const dy = ballon.to_y - ballon.from_y;

        if (dx < 0) targetAnimationName = "left";
        else if (dx > 0) targetAnimationName = "right";
        else if (dy < 0) targetAnimationName = "up";
        else if (dy > 0) targetAnimationName = "down";

        if (ballon.currentAnimationName !== targetAnimationName) {
            ballon.currentAnimationName = targetAnimationName;
            ballon.animationFrame = 0;
            ballon.animationTimer = 0;
        }

        ballon.animationTimer += deltaTime;
        const animConfig = animations_data.ballon;
        const animationSequence = animConfig[ballon.currentAnimationName];
        const numFrames = animationSequence.length;
        const frameLength = animConfig.frame_length;

        if (dx !== 0 || dy !== 0) {
            if (ballon.animationTimer >= frameLength) {
                ballon.animationFrame = (ballon.animationFrame + 1) % numFrames;
                ballon.animationTimer -= frameLength;
            }
        }

        const spriteCoord = animationSequence[ballon.animationFrame];
        sprite_sheet.drawSprite(
            ctx,
            spriteCoord.x, spriteCoord.y,
            ballon.current_x * TILE_SIZE,
            ballon.current_y * TILE_SIZE
        );
    });

    for (const [players_peer_name, player] of Object.entries(players)) {
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

        if (players_peer_name === peer_name && player.state === State.Alive) {
            for (const balloon of balloons) {
                if (
                    player.pos_x < balloon.current_x + 1 &&
                    player.pos_x + 1 > balloon.current_x &&
                    player.pos_y < balloon.current_y + 1 &&
                    player.pos_y + 1 > balloon.current_y
                ) {
                    if (!ws) return;
                    ws.send(JSON.stringify({ type: "player_died", peer_name: players_peer_name }));
                }
            }
        }

        if (player.state === State.Alive) {
            let targetAnimationName: PlayerAnimationName = player.currentAnimationName;
            let newLastDirection: Direction = player.lastDirection;

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
                    player.animationTimer -= frameLength;
                }
            } else {
                player.animationFrame = 0;
            }

            const spriteCoord = animationSequence[player.animationFrame];
            sprite_sheet.drawSprite(ctx, spriteCoord.x, spriteCoord.y, player.pos_x * TILE_SIZE, player.pos_y * TILE_SIZE);
        } else if (player.state === State.Dying) {
            player.animationTimer += deltaTime;
            const animationSequence = animations_data.player.death;
            const numFrames = animationSequence.length;
            const frameLength = animations_data.player.frame_length;

            if (player.animationTimer >= frameLength) {
                player.animationFrame = (player.animationFrame + 1) % numFrames;
                player.animationTimer -= frameLength;
            }

            if (player.animationFrame === numFrames - 1) {
                player.state = State.Dead;
                if (players_peer_name === peer_name) {
                    game_state = State.Dead;
                }
            }

            const spriteCoord = animationSequence[player.animationFrame];
            sprite_sheet.drawSprite(ctx, spriteCoord.x, spriteCoord.y, player.pos_x * TILE_SIZE, player.pos_y * TILE_SIZE);
        }
    }

    requestAnimationFrame(gameLoop);
}

const initGame = async () => {
    console.info("Info: Initializing game...");
    document.addEventListener("keydown", (event) => {
        if (!event.key.startsWith("Arrow") || event.repeat || game_state !== State.Alive) {
            return;
        }
        pressedKeys.add(event.key.slice(5));

        if (ws && peer_name) ws.send(JSON.stringify({ type: "player_move", ...parsePresedKeys(pressedKeys), pos_x: players[peer_name].pos_x, pos_y: players[peer_name].pos_y }));
    });
    document.addEventListener("keyup", (event) => {
        if (!event.key.startsWith("Arrow") || event.repeat || game_state !== State.Alive) {
            return;
        }
        pressedKeys.delete(event.key.slice(5));

        if (ws && peer_name) ws.send(JSON.stringify({ type: "player_move", ...parsePresedKeys(pressedKeys), pos_x: players[peer_name].pos_x, pos_y: players[peer_name].pos_y }));
    });

    populateVoiceList();

    try {
        sprite_sheet = new SpriteSheet(sprite_sheet_src, SPRITE_SIZE, TILE_SIZE);
        await sprite_sheet.init();
        await initWebSocket();

        if (!ws) return;

        ws.onerror = (error) => {
            console.error("Error: WebSocket error", error);
        }
        ws.onclose = (event) => {
            console.warn("Warn: WebSocket closed", event);
            ws = null;
        }
        ws.onmessage = (event) => {
            const message: ServerMessage = JSON.parse(event.data);
            speak()
            switch (message.type) {
                case "connection":
                    console.info("Info: Connection message", message);
                    canvas.width = message.map_width * TILE_SIZE;
                    canvas.height = message.map_height * TILE_SIZE;
                    map = message.map;

                    player_speed = message.player_speed;
                    tick_length = message.tick_length;
                    peer_name = message.peer_name;

                    const clientPlayers: { [key: string]: Player } = {};
                    for (const pName in message.players) {
                        clientPlayers[pName] = addPlayerAnimationDefaults(message.players[pName]);
                    }
                    players = clientPlayers;

                    balloons = message.ballons.map((ballon) => addBalloonAnimationDefaults(ballon, message.accumulated_time));
                    break;

                case "new_player":
                    console.info("Info: New player message", message);
                    players[message.peer_name] = addPlayerAnimationDefaults(message.player);
                    break;

                case "player_disconnected":
                    console.info("Info: Player disconnect message", message);
                    delete players[message.peer_name];
                    break;

                case "player_update":
                    console.info("Info: Player update message", message);
                    const playerToUpdate = players[message.peer_name];
                    if (playerToUpdate) {
                        playerToUpdate.pos_x = message.player.pos_x;
                        playerToUpdate.pos_y = message.player.pos_y;
                        playerToUpdate.vel_x = message.player.vel_x;
                        playerToUpdate.vel_y = message.player.vel_y;
                    }
                    break;

                case "game_update":
                    console.info("Info: Game update message", message);
                    balloons = message.ballons.map(ballon => addBalloonAnimationDefaults(ballon));
                    break;

                case "player_died":
                    console.info("Info: Player died message", message);
                    const playerToDie = players[message.peer_name];
                    if (playerToDie && playerToDie.state === State.Alive) {
                        playerToDie.state = State.Dying;
                        playerToDie.animationFrame = 0;
                        playerToDie.animationTimer = 0;
                        playerToDie.vel_x = 0;
                        playerToDie.vel_y = 0;
                    }

                    if (message.peer_name === peer_name) {
                        game_state = State.Dying;
                    }

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
    if (game_state !== State.Alive) return { vel_x: 0, vel_y: 0 };

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



