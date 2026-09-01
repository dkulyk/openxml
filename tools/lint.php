<?php

declare(strict_types=1);

$directories = [__DIR__ . '/../src', __DIR__ . '/../tests', __DIR__];

foreach ($directories as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
        passthru($command, $exitCode);
        if ($exitCode !== 0) {
            exit($exitCode);
        }
    }
}
