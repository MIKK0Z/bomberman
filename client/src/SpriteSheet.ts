export class SpriteSheet {
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
