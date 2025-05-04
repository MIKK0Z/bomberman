// filepath: /home/mikey/szkola/php/bomberman/client/src/vite-env.d.ts
/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_SERVER_HOST: string;
    readonly VITE_SERVER_PORT: string;
    // Add other environment variables here if you define them
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
