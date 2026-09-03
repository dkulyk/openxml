<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal\Container;

/** @internal Keeps a ZIP archive open for the lifetime of an entry stream. */
final class ZipStreamOwner
{
    public function __construct(private \ZipArchive $archive) {}

    public function __destruct()
    {
        $this->archive->close();
    }
}
