import { defineConfig, loadEnv } from "vite";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, "..", "");

    return {
        envDir: "..",
        define: {
            "import.meta.env.WS_SERVER_HOST": JSON.stringify(env.HOST_2),
            "import.meta.env.WS_SERVER_PORT": JSON.stringify(env.PORT),
        },
    };
});
