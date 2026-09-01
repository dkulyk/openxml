<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

interface RelationshipInterface
{
    public function getId(): string;

    public function getType(): string;

    public function getTarget(): string;

    public function isExternal(): bool;

    public function getTargetPartName(): ?string;

    public function getTargetPart(): ?PartInterface;
}
