<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/check-openapi-routes.php');
passthru($command, $status);
exit($status);
