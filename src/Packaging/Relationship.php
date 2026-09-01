<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

use DK\OpenXml\OpenXmlPackage;

final class Relationship implements RelationshipInterface
{
    public function __construct(private string $id, private string $type, private string $target, private bool $external = false, private ?OpenXmlPackage $package = null, private ?string $sourcePartName = null) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function isExternal(): bool
    {
        return $this->external;
    }

    public function getTargetPartName(): ?string
    {
        return $this->external ? null : PartName::resolveTarget($this->sourcePartName, $this->target);
    }

    public function getTargetPart(): ?PartInterface
    {
        return $this->package === null || $this->external ? null : $this->package->getPart((string) $this->getTargetPartName());
    }
}
