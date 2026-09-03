<?php

declare(strict_types=1);

namespace DK\OpenXml\Internal;

use DK\OpenXml\Exception\OpenXmlException;
use DK\OpenXml\Packaging\PartName;

/**
 * @internal
 *
 * @implements \IteratorAggregate<string, string>
 */
final class PartNameIndex implements \IteratorAggregate
{
    /** @var array<string, string> Comparison key => stored part name. */
    private array $names = [];

    /** @var array<string, array<string, string>> Ancestor key => descendant key => stored part name. */
    private array $descendants = [];

    public function find(string $partName): ?string
    {
        return $this->names[strtolower($partName)] ?? null;
    }

    public function add(string $partName): void
    {
        $key = strtolower(PartName::normalize($partName));
        $this->names[$key] = $partName;

        foreach (self::ancestorKeys($key) as $ancestorKey) {
            $this->descendants[$ancestorKey][$key] = $partName;
        }
    }

    public function remove(string $partName): void
    {
        $key = strtolower(PartName::normalize($partName));
        if (!isset($this->names[$key])) {
            return;
        }

        unset($this->names[$key]);
        foreach (self::ancestorKeys($key) as $ancestorKey) {
            unset($this->descendants[$ancestorKey][$key]);
            if ($this->descendants[$ancestorKey] === []) {
                unset($this->descendants[$ancestorKey]);
            }
        }
    }

    public function assertAvailable(
        string $partName,
        bool $allowExactMatch,
        ?string $excludedPartName = null,
    ): void {
        $key = strtolower(PartName::normalize($partName));
        $excludedKey = $excludedPartName === null ? null : strtolower(PartName::normalize($excludedPartName));
        $exact = $this->names[$key] ?? null;
        if ($exact !== null && $key !== $excludedKey && (!$allowExactMatch || $exact !== $partName)) {
            self::throwConflict($partName, $exact);
        }

        foreach (self::ancestorKeys($key) as $ancestorKey) {
            if (isset($this->names[$ancestorKey]) && $ancestorKey !== $excludedKey) {
                self::throwConflict($partName, $this->names[$ancestorKey]);
            }
        }

        foreach ($this->descendants[$key] ?? [] as $descendantKey => $descendantName) {
            if ($descendantKey !== $excludedKey) {
                self::throwConflict($partName, $descendantName);
            }
        }
    }

    public function getIterator(): \Traversable
    {
        yield from $this->names;
    }

    /** @return list<string> */
    private static function ancestorKeys(string $key): array
    {
        $ancestors = [];
        while (($separator = strrpos($key, '/')) !== false && $separator > 0) {
            $key = substr($key, 0, $separator);
            $ancestors[] = $key;
        }

        return $ancestors;
    }

    private static function throwConflict(string $partName, string $existingPartName): never
    {
        throw new OpenXmlException(sprintf(
            'OPC part name "%s" conflicts with existing part "%s".',
            $partName,
            $existingPartName,
        ));
    }
}
