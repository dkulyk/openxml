<?php

declare(strict_types=1);

namespace DK\OpenXml\Repair;

final class RepairAction
{
    public const REMOVE_DANGLING_RELATIONSHIP = 'remove_dangling_relationship';
    public const REMOVE_INVALID_RELATIONSHIP = 'remove_invalid_relationship';
    public const REMOVE_ORPHAN_RELATIONSHIP_PART = 'remove_orphan_relationship_part';
    public const REMOVE_STALE_CONTENT_TYPE_OVERRIDE = 'remove_stale_content_type_override';
    public const CORRECT_RELATIONSHIP_CONTENT_TYPE = 'correct_relationship_content_type';

    public function __construct(
        public readonly string $type,
        public readonly string $subject,
        public readonly string $description,
    ) {}
}
