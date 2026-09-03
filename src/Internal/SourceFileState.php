<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\ConcurrentModificationException;

/** @internal */
final class SourceFileState
{
    /** @param array{size: int, mtime: int, ctime: int, dev: int, ino: int} $metadata */
    private function __construct(
        private string $filename,
        private array $metadata,
    ) {}

    /** Record the file identity, size, and timestamps that later reads and saves are checked against. */
    public static function capture(string $filename): self
    {
        return new self($filename, self::readMetadata($filename));
    }

    public function assertUnchanged(): void
    {
        if ($this->metadata !== self::readMetadata($this->filename)) {
            throw new ConcurrentModificationException(sprintf(
                'Package "%s" changed on disk after it was opened.',
                $this->filename,
            ));
        }
    }

    /** @return array{size: int, mtime: int, ctime: int, dev: int, ino: int} */
    private static function readMetadata(string $filename): array
    {
        clearstatcache(true, $filename);
        $metadata = @stat($filename);
        if ($metadata === false) {
            throw new ConcurrentModificationException(sprintf(
                'Package "%s" is no longer available.',
                $filename,
            ));
        }

        return [
            'size' => $metadata['size'],
            'mtime' => $metadata['mtime'],
            'ctime' => $metadata['ctime'],
            'dev' => $metadata['dev'],
            'ino' => $metadata['ino'],
        ];
    }
}
