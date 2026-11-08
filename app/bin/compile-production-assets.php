<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

$projectDir = dirname(__DIR__);
require $projectDir.'/vendor/autoload.php';
(new Dotenv())->bootEnv($projectDir.'/.env');

if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev') !== 'prod') {
    // Compiled metadata overrides dynamic AssetMapper resolution even in dev.
    // Remove only generated metadata; keep hashed files and unrelated public files.
    $assetDir = $projectDir.'/public/assets';
    $metadata = [$assetDir.'/manifest.json', $assetDir.'/importmap.json', ...(glob($assetDir.'/entrypoint.*.json') ?: [])];
    foreach ($metadata as $file) {
        if ((is_file($file) || is_link($file)) && !unlink($file)) {
            fwrite(STDERR, "Could not remove stale AssetMapper metadata.\n");
            exit(1);
        }
    }
    fwrite(STDOUT, "Dynamic AssetMapper enabled; stale compiled metadata removed.\n");
    exit(0);
}

$process = new Process([PHP_BINARY, $projectDir.'/bin/console', 'asset-map:compile', '--no-interaction'], $projectDir, timeout: null);
$process->run(static function (string $type, string $buffer): void {
    fwrite(Process::ERR === $type ? STDERR : STDOUT, $buffer);
});

exit($process->getExitCode() ?? 1);
