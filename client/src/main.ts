import "modern-normalize/modern-normalize.css";
import "./style.css";

const WS_HOST = import.meta.env.WS_SERVER_HOST ?? "127.0.0.1";
const WS_PORT = import.meta.env.WS_SERVER_PORT ?? "46089";

const WS_URL = `ws://${WS_HOST}:${WS_PORT}`;

const ws = new WebSocket(WS_URL);