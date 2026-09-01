<?php

declare(strict_types=1);

namespace DK\OpenXml\Repair;

/** @implements \IteratorAggregate<int, RepairAction> */
final class RepairReport implements \IteratorAggregate, \Countable
{
    /** @param list<RepairAction> $actions */
    public function __construct(private array $actions) {}

    /** @return list<RepairAction> */
    public function getActions(): array
    {
        return $this->actions;
    }

    public function isEmpty(): bool
    {
        return $this->actions === [];
    }

    public function count(): int
    {
        return count($this->actions);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->actions;
    }
}
