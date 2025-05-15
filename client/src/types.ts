

export type AnimationFrameCoord = { x: number; y: number };
export type AnimationSequence = Array<AnimationFrameCoord>;
export type AnimationsData = {
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

export enum Tile {
    Wall = 0,
    BreakableWall = 1,
    Grass = 2,
}

export type Direction = "left" | "right" | "up" | "down";

export type ServerPlayer = {
    pos_x: number;
    pos_y: number;
    vel_x: number;
    vel_y: number;
}
export type PlayerAnimationName = Exclude<keyof AnimationsData["player"], "frame_length">;
export type Player = ServerPlayer & {
    currentAnimationName: PlayerAnimationName;
    animationFrame: number;
    animationTimer: number;
    lastDirection: Direction;
};

export type ServerBalloon = {
    from_x: number;
    from_y: number;
    to_x: number;
    to_y: number;
};
export type BallonAnimationName = Exclude<keyof AnimationsData["ballon"], "frame_length">;
export type Balloon = ServerBalloon & {
    current_x: number;
    current_y: number;
    interpolationTimer: number;

    currentAnimationName: BallonAnimationName;
    animationFrame: number;
    animationTimer: number;
};

export type GameMap = Array<Array<Tile>>;
export type ServerMessage =
    | { type: "connection"; map_width: number; map_height: number, map: GameMap, players: { [key: string]: ServerPlayer }, ballons: ServerBalloon[], accumulated_time: number, player_speed: number, tick_length: number, peer_name: string }
    | { type: "new_player"; peer_name: string, player: ServerPlayer }
    | { type: "player_disconnected"; peer_name: string }
    | { type: "player_update", peer_name: string, player: ServerPlayer }
    | { type: "game_update", ballons: Array<ServerBalloon> }
