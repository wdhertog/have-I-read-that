<?php

declare(strict_types=1);

// Composer's autoloader when it's installed. Otherwise a minimal one, so the app
// also runs on hosting without Composer: it has no runtime dependencies.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
    return;
}

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'App\\', 4) === 0) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
