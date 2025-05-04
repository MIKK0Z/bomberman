<?php
$envPath = dirname(__DIR__) . "/.env";

if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), "#") === 0) {
            continue;
        }

        if (preg_match("/^(\w+)=(.*)$/", $line, $matches)) {
            $key = trim($matches[1]);
            $value = trim($matches[2]);
            define($key, $value);
        }
    }
} else {
    fprintf(STDERR, "Warninig: .env file not found at %s.\n", $envPath);
}

if (!defined("HOST")) {
    define("HOST", "127.0.0.1");
    fprintf(STDERR, "Warning: HOST not defined in .env, defaulting to %s.\n", HOST);
}

if (!defined("PORT")) {
    define("PORT", "46089");
    fprintf(STDERR, "Warning: PORT not defined in .env, defaulting to %s.\n", PORT);
}
