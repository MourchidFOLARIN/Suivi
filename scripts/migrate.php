<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../api/config.php';

try {
    $pdo = getPDO();
    runDatabaseMigrations($pdo, $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    fwrite(STDOUT, "Database migrations completed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Database migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
