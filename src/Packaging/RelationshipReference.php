<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

final class RelationshipReference
{
    public function __construct(
        public readonly ?string $sourcePartName,
        public readonly RelationshipInterface $relationship,
    ) {}
}
