import "modern-normalize/modern-normalize.css";
import "./style.css"; // Import your CSS

// Access the environment variables defined in vite.config.ts
const serverHost = import.meta.env.WS_SERVER_HOST;
const serverPort = import.meta.env.WS_SERVER_PORT;

console.log(`Attempting to connect to server at: ws://${serverHost}:${serverPort}`);

// --- Game Constants ---
const TILE_SIZE = 32; // Display size in pixels for each tile on the canvas
const SPRITE_SIZE = 16; // Actual size of the sprite in the spritesheet

// Enum-like object for Tile types (mirroring PHP Enum)
const Tile = {
    Grass: 0,
    Wall: 1,
    BreakableWall: 2,
} as const; // Use 'as const' for stricter typing

type TileType = typeof Tile[keyof typeof Tile]; // Type representing possible tile values

// Sprite coordinates (column, row) in the spritesheet (0-based index)
const TILE_SPRITE_COORDS = {
    [Tile.Grass]: { x: 0, y: 5 },
    [Tile.Wall]: { x: 3, y: 3 },
    [Tile.BreakableWall]: { x: 4, y: 3 },
};

// Player Sprite Coordinates (col, row) - Base for each direction
const PLAYER_SPRITE_BASE_COORDS = {
    left: { x: 0, y: 0 },
    down: { x: 3, y: 0 },
    right: { x: 0, y: 1 },
    up: { x: 3, y: 1 },
};
// const PLAYER_ANIMATION_FRAMES = 3; // Number of frames per direction (0, 1, 2)

// Player Death Sprite Coordinates (col, row) - Assuming 7 frames horizontally
const PLAYER_DEATH_SPRITE_COORDS = {
    startX: 0, // Starting column index
    startY: 2, // Row index
    frameCount: 7, // Number of frames in the death animation
};


// --- Game State ---
interface PlayerState {
    id: number;
    x: number; // Position in tile units
    y: number; // Position in tile units
    direction: keyof typeof PLAYER_SPRITE_BASE_COORDS; // 'left', 'right', 'up', 'down'
    frame: number; // Animation frame (0, 1, 2)
    isAlive: boolean; // Added
    deathFrame: number; // Added
}

interface BalloonState { // Added BalloonState interface
    id: number;
    x: number;
    y: number;
    directionGroup: 'upLeft' | 'downRight';
    frame: number;
}

let gameMap: TileType[][] | null = null; // Store the received map data
let players = new Map<number, PlayerState>(); // Store player states, keyed by player ID
let balloons = new Map<number, BalloonState>(); // Added balloons map
let myPlayerId: number | null = null; // ID assigned by the server to this client

let canvas: HTMLCanvasElement | null = null;
let ctx: CanvasRenderingContext2D | null = null;
let spriteSheet: HTMLImageElement | null = null;
let spriteSheetLoaded = false;
let animationFrameId: number | null = null; // For requestAnimationFrame

// Add variables for offscreen canvas used for color replacement
let offscreenCanvas: HTMLCanvasElement | null = null;
let offscreenCtx: CanvasRenderingContext2D | null = null;
const TRANSPARENT_COLOR_RGB = { r: 54, g: 125, b: 5 }; // RGB for #367D05

// --- Input Handling ---
const keysPressed = new Set<string>(); // Track currently pressed keys

function handleKeyDown(event: KeyboardEvent) {
    const key = event.key;
    if (key.startsWith("Arrow") && !keysPressed.has(key)) {
        keysPressed.add(key);
        sendKeyUpdate(key, 'down');
        // console.log("Key down:", key);
    }
}

function handleKeyUp(event: KeyboardEvent) {
    const key = event.key;
    if (key.startsWith("Arrow")) {
        keysPressed.delete(key);
        sendKeyUpdate(key, 'up');
        // console.log("Key up:", key);
    }
}

function sendKeyUpdate(key: string, state: 'down' | 'up') {
    sendMessage({ type: 'key', key, state });
}

// --- WebSocket Connection ---
const wsUrl = `ws://${serverHost}:${serverPort}`;
let ws: WebSocket | null = null;

function connectWebSocket() {
    if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) {
        console.log("WebSocket connection already open or connecting.");
        return;
    }
    try {
        ws = new WebSocket(wsUrl);

        ws.onopen = () => {
            console.log("WebSocket connection opened");
            // Server will send initial state automatically
            // Add input listeners only when connected
            document.addEventListener('keydown', handleKeyDown);
            document.addEventListener('keyup', handleKeyUp);
        };

        ws.onmessage = (event) => {
            // console.log("Raw message from server:", event.data); // Less verbose logging
            try {
                const message = JSON.parse(event.data);
                // console.log("Parsed message:", message);
                handleServerMessage(message); // Process the message
            } catch (e) {
                console.error("Failed to parse message or invalid JSON:", event.data, e);
            }
        };

        ws.onerror = (error) => {
            console.error("WebSocket error:", error);
            // Clean up listeners on error
            document.removeEventListener('keydown', handleKeyDown);
            document.removeEventListener('keyup', handleKeyUp);
            keysPressed.clear(); // Clear pressed keys state
        };

        ws.onclose = (event) => {
            console.log(`WebSocket connection closed: Code=${event.code}, Reason=${event.reason}`);
            ws = null;
            // Clean up listeners and game state on close
            document.removeEventListener('keydown', handleKeyDown);
            document.removeEventListener('keyup', handleKeyUp);
            keysPressed.clear();
            players.clear(); // Clear player data
            balloons.clear(); // Clear balloons on disconnect
            myPlayerId = null;
            gameMap = null; // Clear map data
            if (animationFrameId) cancelAnimationFrame(animationFrameId); // Stop game loop
            animationFrameId = null;
            // Optional: Show disconnected message on canvas
            if (ctx && canvas) {
                ctx.fillStyle = "#cccccc";
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.fillStyle = "red";
                ctx.font = "16px sans-serif";
                ctx.textAlign = "center";
                ctx.fillText("Disconnected. Attempting to reconnect...", canvas.width / 2, canvas.height / 2);
            }
            // Optional: Implement reconnection logic
            setTimeout(connectWebSocket, 5000); // Reconnect after 5 seconds
        };
    } catch (error) {
        console.error("Failed to create WebSocket connection:", error);
        // Optional: Implement reconnection logic or notify user
        setTimeout(connectWebSocket, 5000);
    }
}

// --- Message Handling ---
function handleServerMessage(message: any) {
    if (message && typeof message === 'object' && message.type) {
        switch (message.type) {
            case 'map':
                if (Array.isArray(message.data)) {
                    gameMap = message.data as TileType[][];
                    console.log("Received map data.");
                    // Map drawing is handled in the game loop now
                } else {
                    console.error("Invalid map data received:", message.data);
                }
                break;
            case 'assignId':
                if (typeof message.id === 'number') {
                    myPlayerId = message.id;
                    console.log("Assigned Player ID:", myPlayerId);
                } else {
                    console.error("Invalid assignId message:", message);
                }
                break;
            case 'playerUpdate':
                if (Array.isArray(message.data)) {
                    // Process batch updates efficiently
                    const updatedIds = new Set<number>();
                    message.data.forEach((pState: any) => {
                        if (isValidPlayerState(pState)) {
                            players.set(pState.id, pState as PlayerState);
                            updatedIds.add(pState.id);
                        } else {
                            console.warn("Received invalid player state in update:", pState);
                        }
                    });
                    // Optional: Remove players not present in the update if this is meant to be a full sync
                    // players.forEach((_, id) => {
                    //     if (!updatedIds.has(id)) {
                    //         players.delete(id);
                    //         console.log(`Removed player ${id} (stale update)`);
                    //     }
                    // });
                } else {
                    console.error("Invalid playerUpdate data:", message.data);
                }
                break;
            case 'playerJoin': // A single new player joined
                if (isValidPlayerState(message.data)) {
                    players.set(message.data.id, message.data as PlayerState);
                    console.log(`Player ${message.data.id} joined.`);
                } else {
                    console.warn("Received invalid player join data:", message.data);
                }
                break;
            case 'playerRemove':
                if (typeof message.id === 'number') {
                    if (players.delete(message.id)) {
                        console.log(`Player ${message.id} removed.`);
                    }
                } else {
                    console.error("Invalid playerRemove message:", message);
                }
                break;
            case 'initialBalloons': // Added handler for initial balloons
                console.log("Received initial balloon states:", message.data);
                balloons.clear(); // Clear existing balloons
                message.data.forEach((balloonState: BalloonState) => {
                    balloons.set(balloonState.id, balloonState);
                });
                break;
            case 'gameStateUpdate':
                // Update players
                if (message.data.players) {
                    const updatedPlayers = new Map<number, PlayerState>();
                    message.data.players.forEach((playerState: PlayerState) => {
                        updatedPlayers.set(playerState.id, playerState);
                    });
                    players = updatedPlayers; // Replace with the latest state
                }
                // Update balloons
                if (message.data.balloons) { // Added balloon update logic
                    const updatedBalloons = new Map<number, BalloonState>();
                    message.data.balloons.forEach((balloonState: BalloonState) => {
                        updatedBalloons.set(balloonState.id, balloonState);
                    });
                    balloons = updatedBalloons; // Replace with the latest state
                }
                break;
            default:
                console.log("Received unhandled message type:", message.type);
        }
    } else {
        console.warn("Received invalid message format:", message);
    }
}

// Helper to validate player state structure
function isValidPlayerState(data: any): data is PlayerState {
    return data &&
        typeof data.id === 'number' &&
        typeof data.x === 'number' &&
        typeof data.y === 'number' &&
        typeof data.direction === 'string' &&
        (data.direction === 'up' || data.direction === 'down' || data.direction === 'left' || data.direction === 'right') &&
        typeof data.frame === 'number';
}


// --- Drawing Logic ---
function drawGame() {
    if (!ctx || !canvas || !spriteSheet || !spriteSheetLoaded) {
        // console.warn("Cannot draw game: Missing context, canvas, or spritesheet not loaded.");
        return; // Don't draw if essentials are missing
    }

    // --- Draw Map ---
    if (!gameMap) {
        // Draw loading state or background if map not received yet
        ctx.fillStyle = "#cccccc";
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = "black";
        ctx.font = "16px sans-serif";
        ctx.textAlign = "center";
        ctx.fillText("Waiting for map data...", canvas.width / 2, canvas.height / 2);
        return; // Don't proceed without map
    }

    const mapHeight = gameMap.length;
    if (mapHeight === 0) return; // Empty map
    const mapWidth = gameMap[0].length;

    // Adjust canvas size if necessary (consider doing this only once or less frequently)
    const requiredWidth = mapWidth * TILE_SIZE;
    const requiredHeight = mapHeight * TILE_SIZE;
    if (canvas.width !== requiredWidth || canvas.height !== requiredHeight) {
        canvas.width = requiredWidth;
        canvas.height = requiredHeight;
        console.log(`Canvas resized to ${canvas.width}x${canvas.height}`);
    }


    // Clear the canvas
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    // Loop through the map data and draw each tile using sprites
    for (let y = 0; y < mapHeight; y++) {
        for (let x = 0; x < mapWidth; x++) {
            const tileType = gameMap[y][x];
            const drawX = x * TILE_SIZE;
            const drawY = y * TILE_SIZE;

            const coords = TILE_SPRITE_COORDS[tileType as keyof typeof TILE_SPRITE_COORDS];

            if (coords) {
                const sourceX = coords.x * SPRITE_SIZE;
                const sourceY = coords.y * SPRITE_SIZE;

                ctx!.drawImage( // Use non-null assertion ctx!
                    spriteSheet!, // Use non-null assertion spriteSheet!
                    sourceX, sourceY, SPRITE_SIZE, SPRITE_SIZE,
                    drawX, drawY, TILE_SIZE, TILE_SIZE
                );
            } else {
                // Fallback for unknown tiles
                ctx.fillStyle = "#FF00FF"; // Bright pink for visibility
                ctx.fillRect(drawX, drawY, TILE_SIZE, TILE_SIZE);
            }
        }
    }
    // --- End Draw Map ---

    // --- Draw Players ---
    players.forEach(player => {
        if (!offscreenCtx || !offscreenCanvas) return; // Skip if offscreen canvas isn't ready

        const drawX = player.x * TILE_SIZE; // Convert tile units to pixels
        const drawY = player.y * TILE_SIZE;

        const baseCoords = PLAYER_SPRITE_BASE_COORDS[player.direction];
        if (baseCoords) {
            // Calculate source X based on direction and animation frame
            const sourceX = (baseCoords.x + player.frame) * SPRITE_SIZE;
            const sourceY = baseCoords.y * SPRITE_SIZE;

            // 1. Clear the offscreen canvas
            offscreenCtx.clearRect(0, 0, TILE_SIZE, TILE_SIZE);

            // 2. Draw the original player sprite onto the offscreen canvas, scaled
            offscreenCtx.drawImage(
                spriteSheet!,
                sourceX, sourceY, SPRITE_SIZE, SPRITE_SIZE, // Source rect
                0, 0, TILE_SIZE, TILE_SIZE             // Destination rect (on offscreen)
            );

            // 3. Get image data from the offscreen canvas
            const imageData = offscreenCtx.getImageData(0, 0, TILE_SIZE, TILE_SIZE);
            const data = imageData.data;

            // 4. Iterate through pixels and make the target green transparent
            for (let i = 0; i < data.length; i += 4) {
                const r = data[i];
                const g = data[i + 1];
                const b = data[i + 2];
                // Check if the pixel color matches the target green
                if (r === TRANSPARENT_COLOR_RGB.r && g === TRANSPARENT_COLOR_RGB.g && b === TRANSPARENT_COLOR_RGB.b) {
                    // Set alpha to 0 (transparent)
                    data[i + 3] = 0;
                }
            }

            // 5. Put the modified image data back onto the offscreen canvas
            offscreenCtx.putImageData(imageData, 0, 0);

            // 6. Draw the modified sprite from the offscreen canvas to the main canvas
            ctx!.drawImage( // Use non-null assertion ctx!
                offscreenCanvas, // Draw the processed image
                drawX - (TILE_SIZE / 2),        // Destination X on canvas (center sprite)
                drawY - (TILE_SIZE / 2)         // Destination Y on canvas (center sprite)
            );

            // Optional: Draw player ID above them
            // ctx.fillStyle = "white";
            // ctx.font = "10px sans-serif";
            // ctx.textAlign = "center";
            // ctx.fillText(player.id.toString(), drawX + TILE_SIZE / 2, drawY - 2);

        } else {
            console.warn(`Invalid direction ${player.direction} for player ${player.id}`);
            // Fallback drawing for player
            ctx!.fillStyle = "blue"; // Use non-null assertion ctx!
            ctx!.fillRect(drawX, drawY, TILE_SIZE, TILE_SIZE); // Use non-null assertion ctx!
        }
    });
    // --- End Draw Players ---

    // --- Draw Balloons ---
    balloons.forEach(balloon => {
        if (!offscreenCtx || !offscreenCanvas) return; // Skip if offscreen canvas isn't ready

        const drawX = balloon.x * TILE_SIZE; // Convert tile units to pixels
        const drawY = balloon.y * TILE_SIZE;

        const baseCoords = PLAYER_SPRITE_BASE_COORDS[balloon.directionGroup];
        if (baseCoords) {
            // Calculate source X based on direction and animation frame
            const sourceX = (baseCoords.x + balloon.frame) * SPRITE_SIZE;
            const sourceY = baseCoords.y * SPRITE_SIZE;

            // 1. Clear the offscreen canvas
            offscreenCtx.clearRect(0, 0, TILE_SIZE, TILE_SIZE);

            // 2. Draw the original balloon sprite onto the offscreen canvas, scaled
            offscreenCtx.drawImage(
                spriteSheet!,
                sourceX, sourceY, SPRITE_SIZE, SPRITE_SIZE, // Source rect
                0, 0, TILE_SIZE, TILE_SIZE             // Destination rect (on offscreen)
            );

            // 3. Get image data from the offscreen canvas
            const imageData = offscreenCtx.getImageData(0, 0, TILE_SIZE, TILE_SIZE);
            const data = imageData.data;

            // 4. Iterate through pixels and make the target green transparent
            for (let i = 0; i < data.length; i += 4) {
                const r = data[i];
                const g = data[i + 1];
                const b = data[i + 2];
                // Check if the pixel color matches the target green
                if (r === TRANSPARENT_COLOR_RGB.r && g === TRANSPARENT_COLOR_RGB.g && b === TRANSPARENT_COLOR_RGB.b) {
                    // Set alpha to 0 (transparent)
                    data[i + 3] = 0;
                }
            }

            // 5. Put the modified image data back onto the offscreen canvas
            offscreenCtx.putImageData(imageData, 0, 0);

            // 6. Draw the modified sprite from the offscreen canvas to the main canvas
            ctx!.drawImage( // Use non-null assertion ctx!
                offscreenCanvas, // Draw the processed image
                drawX - (TILE_SIZE / 2),        // Destination X on canvas (center sprite)
                drawY - (TILE_SIZE / 2)         // Destination Y on canvas (center sprite)
            );

        } else {
            console.warn(`Invalid direction ${balloon.directionGroup} for balloon ${balloon.id}`);
            // Fallback drawing for balloon
            ctx!.fillStyle = "blue"; // Use non-null assertion ctx!
            ctx!.fillRect(drawX, drawY, TILE_SIZE, TILE_SIZE); // Use non-null assertion ctx!
        }
    });
    // --- End Draw Balloons ---
}

// --- Game Loop ---
function gameLoop() {
    drawGame(); // Draw the current state
    animationFrameId = requestAnimationFrame(gameLoop); // Request next frame
}

// --- Sprite Loading ---
function loadSpriteSheet(callback: () => void) {
    spriteSheet = new Image();
    spriteSheet.onload = () => {
        console.log("Spritesheet loaded successfully.");
        spriteSheetLoaded = true;
        callback(); // Call the callback function (e.g., start game loop)
    };
    spriteSheet.onerror = () => {
        console.error("Failed to load spritesheet.");
        spriteSheetLoaded = false;
        // Display error message on the canvas
        if (ctx && canvas) {
            ctx.fillStyle = "red";
            ctx.font = "16px sans-serif";
            ctx.textAlign = "center";
            ctx.fillText("Error loading spritesheet!", canvas.width / 2, canvas.height / 2 + 20);
        }
    };
    spriteSheet.src = "/sprite_sheet.png"; // Assumes sprite_sheet.png is in the public folder
}

// --- Utility: Sending a message ---
function sendMessage(message: object) {
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify(message));
    } else {
        console.warn("WebSocket is not open. Message not sent:", message);
    }
}

// --- Initialization ---
function initializeGame() {
    const app = document.querySelector<HTMLDivElement>('#app');
    if (app) {
        app.innerHTML = `
        <h1>Bomberman</h1>
        <canvas id="gameCanvas" width="480" height="320"></canvas> <!-- Initial size -->
      `;
        canvas = document.getElementById('gameCanvas') as HTMLCanvasElement;
        ctx = canvas.getContext('2d');

        if (!canvas || !ctx) {
            console.error("Failed to get canvas element or 2D context.");
            app.innerHTML = "<h1>Error: Could not initialize canvas</h1>";
            return;
        }
        // Prevent image smoothing for pixel art
        ctx.imageSmoothingEnabled = false;

        // Initialize the offscreen canvas
        offscreenCanvas = document.createElement('canvas');
        offscreenCtx = offscreenCanvas.getContext('2d', { willReadFrequently: true }); // willReadFrequently for performance
        if (offscreenCanvas && offscreenCtx) {
            offscreenCanvas.width = TILE_SIZE; // Set size for one tile
            offscreenCanvas.height = TILE_SIZE;
            offscreenCtx.imageSmoothingEnabled = false; // Match main canvas setting
        } else {
            console.error("Failed to create offscreen canvas context.");
            // Handle error appropriately, maybe disable transparency feature
        }

        // Set initial canvas message
        ctx.fillStyle = "#cccccc";
        ctx.fillRect(0, 0, canvas.width, canvas.height); // Default background
        ctx.fillStyle = "black";
        ctx.font = "16px sans-serif";
        ctx.textAlign = "center";
        ctx.fillText("Connecting to server...", canvas.width / 2, canvas.height / 2);

        // Load spritesheet, and start game loop once loaded
        loadSpriteSheet(() => {
            // Start the game loop only after the spritesheet is ready
            if (!animationFrameId) { // Prevent multiple loops
                gameLoop();
            }
        });

        // Start WebSocket connection
        connectWebSocket();

    } else {
        console.error("Could not find #app element");
    }
}

// Start the game initialization when the DOM is ready
document.addEventListener('DOMContentLoaded', initializeGame);