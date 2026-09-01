<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\OpenXmlException;

/** @internal */
final class AtomicFileWriter
{
    private function __construct() {}

    /**
     * @param \Closure(string): void $writeTemporaryFile
     * @param null|\Closure(): void  $beforeReplace
     */
    public static function replace(
        string $filename,
        \Closure $writeTemporaryFile,
        ?\Closure $beforeReplace = null,
    ): void {
        $directory = dirname($filename);
        if (!is_dir($directory)) {
            throw new OpenXmlException(sprintf('Directory "%s" does not exist.', $directory));
        }

        $temporaryFilename = @tempnam($directory, '.' . basename($filename) . '.tmp-');
        if ($temporaryFilename === false) {
            throw new OpenXmlException(sprintf(
                'Unable to create a temporary file next to "%s".',
                $filename,
            ));
        }

        try {
            $writeTemporaryFile($temporaryFilename);
            self::preservePermissions($filename, $temporaryFilename);
            if ($beforeReplace !== null) {
                $beforeReplace();
            }

            if (!@rename($temporaryFilename, $filename)) {
                throw new OpenXmlException(sprintf(
                    'Unable to atomically replace "%s".',
                    $filename,
                ));
            }

            $temporaryFilename = null;
        } finally {
            if ($temporaryFilename !== null && is_file($temporaryFilename)) {
                @unlink($temporaryFilename);
            }
        }
    }

    private static function preservePermissions(string $source, string $temporaryFile): void
    {
        if (!is_file($source)) {
            return;
        }

        $permissions = fileperms($source);
        if ($permissions !== false) {
            @chmod($temporaryFile, $permissions & 0777);
        }
    }
}
