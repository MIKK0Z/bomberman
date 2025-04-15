import "./style.css";
import sprite_sheet_src from "./assets/sprite_sheet.png";

const WS_HOST = "localhost";
const WS_PORT = 46089;
const WS_URL = `ws://${WS_HOST}:${WS_PORT}`;

const GAME_WIDTH = 31;
const GAME_HEIGHT = 13;
const TILE_SIZE = 16;
const SCALE = 2;

class SpriteSheet {
    private readonly sprite_sheet_src: string;
    private sprite_sheet: HTMLImageElement | null = null;

    private readonly tile_size: number;
    private readonly scale: number;

    constructor(sprite_sheet_src: string, tile_size: number, scale: number) {
        this.sprite_sheet_src = sprite_sheet_src;

        this.tile_size = tile_size;
        this.scale = scale;
    }

    public init(): void {
        this.sprite_sheet = new Image();
        this.sprite_sheet.src = this.sprite_sheet_src;
    }

    public drawBarrier(ctx: CanvasRenderingContext2D, x: number, y: number): void {
        if (!this.sprite_sheet) throw new Error("Sprite sheet not initialized!");

        ctx.drawImage(
            this.sprite_sheet,
            this.tile_size * 3, this.tile_size * 3,
            this.tile_size, this.tile_size,
            this.tile_size * this.scale * x, this.tile_size * this.scale * y,
            this.tile_size * this.scale, this.tile_size * this.scale,
        );
    }

    public drawBreakableBarrier(ctx: CanvasRenderingContext2D, x: number, y: number): void {
        if (!this.sprite_sheet) throw new Error("Sprite sheet not initialized!");

        ctx.drawImage(
            this.sprite_sheet,
            this.tile_size * 4, this.tile_size * 3,
            this.tile_size, this.tile_size,
            this.tile_size * this.scale * x, this.tile_size * this.scale * y,
            this.tile_size * this.scale, this.tile_size * this.scale,
        );
    }

    public drawGrass(ctx: CanvasRenderingContext2D, x: number, y: number): void {
        if (!this.sprite_sheet) throw new Error("Sprite sheet not initialized!");

        ctx.drawImage(
            this.sprite_sheet,
            this.tile_size * 0, this.tile_size * 5,
            this.tile_size, this.tile_size,
            this.tile_size * this.scale * x, this.tile_size * this.scale * y,
            this.tile_size * this.scale, this.tile_size * this.scale,
        );
    }
}

namespace SpriteSheet {
    export enum Tile {
        Barrier = "B",
        BreakableBarrier = "C",
        Grass = "G",
    }
}


type GameData = {
    map: Array<Array<SpriteSheet.Tile.Barrier | SpriteSheet.Tile.BreakableBarrier | SpriteSheet.Tile.Grass>>,
};


const app = document.querySelector("#app");

const canvas = document.createElement("canvas");
canvas.width = GAME_WIDTH * TILE_SIZE * SCALE;
canvas.height = GAME_HEIGHT * TILE_SIZE * SCALE;
const ctx = canvas.getContext("2d");
app?.appendChild(canvas);

const sprite_sheet = new SpriteSheet(sprite_sheet_src, TILE_SIZE, SCALE);
sprite_sheet.init();

const ws = new WebSocket(WS_URL);

const send = (data: any) => {
    ws.send(JSON.stringify(data));
}

const init = () => {
    ws.onopen = () => {
        console.log("connected");
    }

    ws.onmessage = (event) => {
        const jsonData = event.data;
        if (jsonData != "") {
            try {
                const gameData: GameData = JSON.parse(jsonData);
                console.log(gameData)

                const { map } = gameData;

                if (!map || !ctx) return;

                ctx.clearRect(0, 0, canvas.width, canvas.height);

                map.forEach((row, y) => {
                    row.forEach((cell, x) => {
                        switch (cell) {
                            case SpriteSheet.Tile.Barrier:
                                sprite_sheet.drawBarrier(ctx, x, y);
                                break;

                            case SpriteSheet.Tile.BreakableBarrier:
                                sprite_sheet.drawBreakableBarrier(ctx, x, y);
                                break;

                            case SpriteSheet.Tile.Grass:
                                sprite_sheet.drawGrass(ctx, x, y);
                                break;


                            default:
                                break;
                        }
                    })
                });

            } catch (error) {
                console.log(error);
            }
        }
    }

    ws.onclose = () => {
        console.log("disconnected");
    }

    ws.onerror = (error) => {
        console.error(error);
    }
}

init();

window.addEventListener("keydown", (event) => {
    // TODO zrobić żeby się tylko raz wysyłało jak sie trzyma strzałek
    console.warn(event)
    switch (event.key) {
        case "ArrowUp":
            send("start_up");
            break;

        case "ArrowRight":
            send("start_right");
            break;

        case "ArrowDown":
            send("start_down");
            break;

        case "ArrowLeft":
            send("start_left");
            break;

        default:
            break;
    }
});

window.addEventListener("keyup", (event) => {
    switch (event.key) {
        case "ArrowUp":
            send("end_up");
            break;

        case "ArrowRight":
            send("end_right");
            break;

        case "ArrowDown":
            send("end_down");
            break;

        case "ArrowLeft":
            send("end_left");
            break;

        default:
            break;
    }
});
