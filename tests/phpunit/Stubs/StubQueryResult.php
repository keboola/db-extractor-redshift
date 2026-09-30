<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Tests\Stubs;

use ArrayIterator;
use Iterator;
use Keboola\DbExtractor\Adapter\ValueObject\QueryMetadata;
use Keboola\DbExtractor\Adapter\ValueObject\QueryResult;
use LogicException;
use stdClass;

/**
 * Returns a fixed set of rows without touching a database.
 */
class StubQueryResult implements QueryResult
{
    /** @var array<array<string, mixed>> */
    private array $rows;

    /** @param array<array<string, mixed>> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function getQuery(): string
    {
        return '';
    }

    public function getMetadata(): QueryMetadata
    {
        throw new LogicException('Query metadata is not available in the stub.');
    }

    public function getIterator(): Iterator
    {
        return new ArrayIterator($this->rows);
    }

    public function fetch(): ?array
    {
        return array_shift($this->rows);
    }

    public function fetchAll(): array
    {
        $rows = $this->rows;
        $this->rows = [];
        return $rows;
    }

    public function closeCursor(): void
    {
    }

    public function getResource(): object
    {
        return new stdClass();
    }
}
