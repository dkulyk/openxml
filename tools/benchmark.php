<?php

declare(strict_types=1);

use DK\OpenXml\OpenXmlPackage;

require dirname(__DIR__) . '/vendor/autoload.php';

$filename = tempnam(sys_get_temp_dir(), 'openxml-benchmark-');
$copy = tempnam(sys_get_temp_dir(), 'openxml-benchmark-copy-');
if ($filename === false || $copy === false) {
    fwrite(STDERR, "Unable to create benchmark files.\n");
    exit(1);
}

$payload = tmpfile();
if ($payload === false) {
    fwrite(STDERR, "Unable to create the benchmark payload.\n");
    exit(1);
}

try {
    $payloadBytes = 16 * 1024 * 1024;
    for ($written = 0; $written < $payloadBytes; $written += 65_536) {
        fwrite($payload, random_bytes(min(65_536, $payloadBytes - $written)));
    }
    rewind($payload);

    $package = OpenXmlPackage::create();
    $package->addPart('/document.xml', 'application/xml', '<document/>');
    $package->addPartFromStream('/media/payload.bin', 'application/octet-stream', $payload);
    $package->saveAs($filename);
    unset($package);
    gc_collect_cycles();

    $memoryBefore = memory_get_usage(true);
    $started = hrtime(true);
    $package = OpenXmlPackage::open($filename);
    $openSeconds = (hrtime(true) - $started) / 1_000_000_000;
    $openMemory = memory_get_usage(true) - $memoryBefore;
    printf("Lazy open: %.3f ms, %s memory increase\n", $openSeconds * 1000, bytes($openMemory));

    $started = hrtime(true);
    $stream = $package->getPart('/media/payload.bin')->openStream();
    $hash = hash_init('sha256');
    hash_update_stream($hash, $stream);
    hash_final($hash);
    fclose($stream);
    $readSeconds = (hrtime(true) - $started) / 1_000_000_000;
    printf(
        "16 MiB streamed extraction: %.3f s, %.0f MiB/s\n",
        $readSeconds,
        16 / $readSeconds,
    );

    $package->getPart('/document.xml')->setContents('<updated/>');
    $started = hrtime(true);
    $package->saveAs($copy);
    $saveSeconds = (hrtime(true) - $started) / 1_000_000_000;
    printf("Copy-through save: %.3f ms\n", $saveSeconds * 1000);
} finally {
    fclose($payload);
    if (is_file($filename)) {
        unlink($filename);
    }
    if (is_file($copy)) {
        unlink($copy);
    }
}

function bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    return sprintf('%.1f MiB', $bytes / 1024 / 1024);
}
