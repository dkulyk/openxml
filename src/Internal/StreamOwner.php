<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

/** @internal Retains stream-scoped state and reports when the stream releases it. */
final class StreamOwner
{
    /** @param \Closure(): void $release */
    public function __construct(private \Closure $release) {}

    public function __destruct()
    {
        ($this->release)();
    }
}
