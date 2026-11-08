<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

$projectDir = dirname(__DIR__);
require $projectDir.'/vendor/autoload.php';
(new Dotenv())->bootEnv($projectDir.'/.env');

if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev') !== 'prod') {
    fwrite(STDOUT, "Skipping static asset publication outside production.\n");
    exit(0);
}

$process = new Process([PHP_BINARY, $projectDir.'/bin/console', 'asset-map:compile', '--no-interaction'], $projectDir, timeout: null);
$process->run(static function (string $type, string $buffer): void {
    fwrite(Process::ERR === $type ? STDERR : STDOUT, $buffer);
});

exit($process->getExitCode() ?? 1);
