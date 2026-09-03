<?php

declare(strict_types=1);

namespace DK\OpenXml\Packaging;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\OpenXmlPackage;

final class Relationship implements RelationshipInterface
{
    /** @var null|\WeakReference<OpenXmlPackage> */
    private ?\WeakReference $package;

    public function __construct(
        private string $id,
        private string $type,
        private string $target,
        private bool $external = false,
        ?OpenXmlPackage $package = null,
        private ?string $sourcePartName = null,
    ) {
        $this->package = $package === null ? null : \WeakReference::create($package);
    }

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
        if ($this->package === null || $this->external) {
            return null;
        }
        $package = $this->package->get()
            ?? throw new OpenXmlException('The package owning this relationship has been released.');

        return $package->getPart((string) $this->getTargetPartName());
    }
}
