<?php

namespace Omnilab\Vizier;

use Omnilab\Model\Property;

/**
 * One row of a VizieR table (outside the contract): its table, its
 * separation from the point asked, and its values by column - each a
 * Property whose key is the column's name, whose label is VizieR's
 * description, and whose value is a Quantity when the column has a unit.
 */
final readonly class Row
{
    /** @param array<string, Property> $values */
    public function __construct(
        public string $table,
        public ?float $separation,
        public array $values,
    ) {
    }

    /** A column's value: a Quantity, a number, a text; null when empty or absent. */
    public function value(string $column): mixed
    {
        return $this->values[$column]?->value ?? null;
    }
}
