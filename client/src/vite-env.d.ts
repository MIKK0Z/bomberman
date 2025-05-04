/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly WS_SERVER_HOST: string;
    readonly WS_SERVER_PORT: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}