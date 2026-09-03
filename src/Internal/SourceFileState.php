<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\ConcurrentModificationException;
use DK\OpenXml\Exception\OpenXmlException;

/** @internal */
final class SourceFileState
{
    /** @param array{size: int, mtime: int, ctime: int, dev: int, ino: int} $metadata */
    private function __construct(
        private string $filename,
        private string $fingerprint,
        private array $metadata,
    ) {}

    public static function capture(string $filename): self
    {
        $metadataBeforeHashing = self::readMetadata($filename);
        $fingerprint = @hash_file('sha256', $filename);
        if ($fingerprint === false) {
            throw new OpenXmlException(sprintf('Unable to fingerprint package "%s".', $filename));
        }

        $state = new self($filename, $fingerprint, self::readMetadata($filename));
        if ($metadataBeforeHashing !== $state->metadata) {
            throw new ConcurrentModificationException(sprintf(
                'Package "%s" changed while it was being opened.',
                $filename,
            ));
        }

        return $state;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
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
