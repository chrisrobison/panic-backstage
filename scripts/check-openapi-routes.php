<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Contract check for the hand-written router and OpenAPI document.
 *
 * It verifies:
 *   - every documented application path resolves to a real endpoint class;
 *   - every Kernel top-level route family has a documented path;
 *   - every Booking Inbox lead child and cross-lead action is documented;
 *   - path keys and operationIds are unique and API paths are consistently
 *     prefixed (apart from the documented /super and /feeds aliases).
 */

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';

use Panic\Kernel;

$specFile = $root . '/docs/openapi.yaml';
$kernelFile = $root . '/src/Kernel.php';
$inboxFile = $root . '/src/Inbox.php';
$spec = (string) file_get_contents($specFile);
$kernelSource = (string) file_get_contents($kernelFile);
$inboxSource = (string) file_get_contents($inboxFile);
$errors = [];

preg_match_all('/^  (\/[^:\n]+):\s*$/m', $spec, $pathMatches);
$paths = $pathMatches[1];
$pathSet = array_fill_keys($paths, true);

foreach (array_count_values($paths) as $path => $count) {
    if ($count > 1) {
        $errors[] = "duplicate OpenAPI path: {$path}";
    }
    if ($path !== '/super'
        && !str_starts_with($path, '/feeds/')
        && !str_starts_with($path, '/api/')
    ) {
        $errors[] = "API path is not /api-prefixed: {$path}";
    }
}

preg_match_all('/^\s+operationId:\s*(\S+)\s*$/m', $spec, $operationMatches);
foreach (array_count_values($operationMatches[1]) as $operationId => $count) {
    if ($count > 1) {
        $errors[] = "duplicate operationId: {$operationId}";
    }
}

// resolve() is pure routing logic, so it can be inspected without booting a
// Database or touching the production environment.
$kernel = (new ReflectionClass(Kernel::class))->newInstanceWithoutConstructor();
$resolve = new ReflectionMethod(Kernel::class, 'resolve');
$resolve->setAccessible(true);

foreach ($paths as $path) {
    // The super-admin surface has its own router under public/super; /super is
    // its HTML shell and is intentionally outside the staff API Kernel.
    if ($path === '/super' || str_starts_with($path, '/api/super/')) {
        continue;
    }
    $sample = preg_replace_callback(
        '/\{([^}]+)\}/',
        static fn (array $match): string => str_contains(strtolower($match[1]), 'token') ? 'sample-token' : '1',
        $path
    );
    [$class] = $resolve->invoke($kernel, $sample);
    if (!is_string($class) || !class_exists($class)) {
        $errors[] = "{$path} resolves to missing endpoint class " . (is_string($class) ? $class : '(invalid)');
    }
}

// Reverse coverage at the route-family level: a new top-level Kernel branch
// cannot land without at least one matching OpenAPI path.
preg_match_all("/\\\$segments\\[0\\]\\s*===\\s*'([^']+)'/", $kernelSource, $familyMatches);
$documentedFamilies = [];
foreach ($paths as $path) {
    if (preg_match('#^/api/([^/{]+)#', $path, $match)) {
        $documentedFamilies[$match[1]] = true;
    } elseif (str_starts_with($path, '/feeds/')) {
        $documentedFamilies['feeds'] = true;
    }
}
foreach (array_unique($familyMatches[1]) as $family) {
    if (!isset($documentedFamilies[$family])) {
        $errors[] = "Kernel route family /api/{$family} has no OpenAPI path";
    }
}

// The Booking Inbox multiplexes many actions through two endpoint classes.
// Check those child/action lists explicitly so adding a child to an existing
// family cannot evade the family-level reverse check above.
if (preg_match('/if \\(in_array\\(\\$child, \\[(.*?)\\], true\\)\\)/s', $kernelSource, $leadBlock)) {
    preg_match_all("/'([^']+)'/", $leadBlock[1], $leadChildren);
    foreach ($leadChildren[1] as $child) {
        $path = "/api/leads/{leadId}/{$child}";
        if (!isset($pathSet[$path])) {
            $errors[] = "Booking Inbox lead route missing from OpenAPI: {$path}";
        }
    }
} else {
    $errors[] = 'Could not locate the Booking Inbox lead-child route list in Kernel.php';
}

if (preg_match('/return match \\(\\$action\\) \\{(.*?)\\n\\s*\\};/s', $inboxSource, $inboxBlock)) {
    preg_match_all("/^\\s*'([^']+)'\\s*=>/m", $inboxBlock[1], $inboxActions);
    foreach ($inboxActions[1] as $action) {
        $path = "/api/inbox/{$action}";
        if (!isset($pathSet[$path])) {
            $errors[] = "Booking Inbox action missing from OpenAPI: {$path}";
        }
    }
} else {
    $errors[] = 'Could not locate the Booking Inbox action route list in Inbox.php';
}

if ($errors !== []) {
    fwrite(STDERR, "OpenAPI route contract failed:\n - " . implode("\n - ", array_unique($errors)) . "\n");
    exit(1);
}

echo 'OpenAPI route contract: ' . count($paths) . " paths checked.\n";
