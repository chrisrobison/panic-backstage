<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

Panic\Kernel::boot(dirname(__DIR__, 2))->handle()->send();
