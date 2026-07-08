<?php
declare(strict_types=1);

// The app's business logic (lead intake timestamps, run sheets, settlement
// dates, etc.) assumes the venue's local time throughout. Set this explicitly
// rather than relying on the server's ambient `date.timezone` ini setting,
// which is UTC on a stock PHP install (and on CI runners) but was previously
// only correct by accident on boxes provisioned with a Pacific default.
date_default_timezone_set('America/Los_Angeles');

spl_autoload_register(function (string $class): void {
    $prefix = 'Panic\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/Support.php';
