<?php

use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

// Credentials belong to the disposable test server; never read an application .env.
if (getenv('LPM_TEST_USERNAME') === false) {
    throw new LogicException('Set LPM_TEST_USERNAME and test-server credentials explicitly.');
}
$host = getenv('LPM_TEST_HOST') ?: '127.0.0.1';
$port = getenv('LPM_TEST_PORT') ?: '3306';
$database = 'lpm_test_'.bin2hex(random_bytes(8));
$pdo = new PDO('mysql:host='.$host.';port='.$port, getenv('LPM_TEST_USERNAME'), getenv('LPM_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
if (! str_contains($version, 'MariaDB')) {
    throw new LogicException('This suite verifies MariaDB; MySQL is not yet certified by these tests.');
}
echo 'MariaDB '.$version.PHP_EOL;
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $process = new Process([PHP_BINARY, 'vendor/bin/pest', '-c', 'phpunit.mariadb.xml', '--compact'], dirname(__DIR__, 2), [
        'APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'LPM_DISPOSABLE_DATABASE' => '1', 'LPM_TEST_DATABASE' => $database,
    ], timeout: 1800);
    $exit = $process->run(fn ($type, $buffer) => print ($buffer));
} finally {
    // Only this generated database, never an externally supplied name.
    $pdo->exec('DROP DATABASE `'.$database.'`');
}
exit($exit);
