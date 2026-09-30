<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Tests\Stubs;

use Keboola\DbExtractor\Adapter\ValueObject\QueryResult;
use Keboola\DbExtractor\Extractor\RedshiftPdoConnection;
use LogicException;

/**
 * Records the SQL it is given and replays canned rows, so the metadata queries
 * can be asserted without a running Redshift.
 */
class CapturingRedshiftPdoConnection extends RedshiftPdoConnection
{
    public const TABLES_MARKER = 'information_schema.tables';
    public const COLUMNS_MARKER = 'information_schema.columns';
    public const LATE_BIND_VIEWS_MARKER = 'pg_get_late_binding_view_cols';
    public const DESCRIPTIONS_MARKER = 'OBJ_DESCRIPTION';

    /** @var string[] */
    private array $queries = [];

    /** @var array<array<string, mixed>> */
    private array $tables;

    /** @var array<array<string, mixed>> */
    private array $columns;

    /** @var array<array<string, mixed>> */
    private array $descriptions;

    /**
     * @param array<array<string, mixed>> $tables
     * @param array<array<string, mixed>> $columns
     * @param array<array<string, mixed>> $descriptions
     */
    public function __construct(array $tables = [], array $columns = [], array $descriptions = [])
    {
        // Deliberately does not call the parent constructor, which would connect
        $this->tables = $tables;
        $this->columns = $columns;
        $this->descriptions = $descriptions;
    }

    public function query(string $query, int $maxRetries = 1): QueryResult
    {
        $this->queries[] = $query;

        if (str_contains($query, self::DESCRIPTIONS_MARKER)) {
            return new StubQueryResult($this->descriptions);
        }
        if (str_contains($query, self::COLUMNS_MARKER)) {
            return new StubQueryResult($this->columns);
        }
        if (str_contains($query, self::TABLES_MARKER)) {
            return new StubQueryResult($this->tables);
        }
        if (str_contains($query, self::LATE_BIND_VIEWS_MARKER)) {
            return new StubQueryResult([]);
        }

        throw new LogicException(sprintf('Unexpected query "%s".', $query));
    }

    public function quote(string $str): string
    {
        return "'" . $str . "'";
    }

    /**
     * @return string[]
     */
    public function getQueries(): array
    {
        return $this->queries;
    }

    public function getQueryContaining(string $marker): string
    {
        foreach ($this->queries as $query) {
            if (str_contains($query, $marker)) {
                return $query;
            }
        }

        throw new LogicException(sprintf('No query containing "%s" was executed.', $marker));
    }

    public function hasQueryContaining(string $marker): bool
    {
        foreach ($this->queries as $query) {
            if (str_contains($query, $marker)) {
                return true;
            }
        }

        return false;
    }
}
