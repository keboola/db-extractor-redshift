<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Extractor;

use Keboola\DbExtractor\Adapter\Metadata\MetadataProvider;
use Keboola\DbExtractor\TableResultFormat\Metadata\Builder\ColumnBuilder;
use Keboola\DbExtractor\TableResultFormat\Metadata\Builder\MetadataBuilder;
use Keboola\DbExtractor\TableResultFormat\Metadata\Builder\TableBuilder;
use Keboola\DbExtractor\TableResultFormat\Metadata\ValueObject\Table;
use Keboola\DbExtractor\TableResultFormat\Metadata\ValueObject\TableCollection;
use Keboola\DbExtractorConfig\Configuration\ValueObject\InputTable;
use PDOStatement;

class RedshiftMetadataProvider implements MetadataProvider
{
    private RedshiftPdoConnection $db;

    /** If false, table and column COMMENTs are not read and no descriptions are propagated */
    private bool $propagateDescriptions;

    public function __construct(RedshiftPdoConnection $db, bool $propagateDescriptions = true)
    {
        $this->db = $db;
        $this->propagateDescriptions = $propagateDescriptions;
    }

    public function getTable(InputTable $table): Table
    {
        return $this
            ->listTables([$table])
            ->getByNameAndSchema($table->getName(), $table->getSchema());
    }

    /**
     * @param array|InputTable[] $whitelist
     * @param bool $loadColumns if false, columns metadata are NOT loaded, useful useful if there are a lot of tables
     */
    public function listTables(array $whitelist = [], bool $loadColumns = true): TableCollection
    {
        $tableBuilders = [];

        // Process tables
        $tableRequiredProperties = ['schema', 'type'];
        $columnRequiredProperties= ['ordinalPosition', 'nullable'];

        $builder = MetadataBuilder::create($tableRequiredProperties, $columnRequiredProperties);
        $nameSchemas = [];
        $nameTables = [];

        foreach ($this->queryTables($whitelist) as $item) {
            $tableId = $item['table_schema'] . '.' . $item['table_name'];
            $tableBuilder = $builder->addTable();
            $tableBuilders[$tableId] = $tableBuilder;

            if ($loadColumns === false) {
                $tableBuilder->setColumnsNotExpected();
            }

            $this->processTableData($tableBuilder, $item);

            $nameSchemas[] = $item['table_schema'];
            $nameTables[] = $item['table_name'];
        }

        // COMMENT ON TABLE / COMMENT ON COLUMN of the tables listed above
        $descriptions = $this->loadDescriptions($nameSchemas, $nameTables, $loadColumns);
        foreach ($descriptions as $tableId => $tableDescriptions) {
            if (isset($tableBuilders[$tableId]) && $tableDescriptions['table'] !== null) {
                $tableBuilders[$tableId]->setDescription($tableDescriptions['table']);
            }
        }

        if ($loadColumns) {
            foreach ($this->queryColumns($nameSchemas, $nameTables) as $column) {
                $tableId = $column['table_schema'] . '.' . $column['table_name'];
                if (!isset($tableBuilders[$tableId])) {
                    continue;
                }
                $columnBuilder = $tableBuilders[$tableId]->addColumn();
                $this->processColumnData($columnBuilder, $column);

                $columnDescription = $descriptions[$tableId]['columns'][$column['column_name']] ?? null;
                if ($columnDescription !== null) {
                    $columnBuilder->setDescription($columnDescription);
                }
            }

            foreach ($this->queryLateBindViewsColumns() as $column) {
                $tableId = $column['view_schema'] . '.' . $column['view_name'];
                if (!isset($tableBuilders[$tableId])) {
                    continue;
                }
                $columnBuilder = $tableBuilders[$tableId]->addColumn();

                $columnBuilder
                    ->setName($column['col_name'])
                    ->setType($column['col_type'])
                    ->setNullable(false)
                    ->setOrdinalPosition($column['col_num']);
            }
        }

        return $builder->build();
    }

    private function processTableData(TableBuilder $builder, array $data): void
    {
        $builder
            ->setSchema($data['table_schema'])
            ->setName($data['table_name'])
            ->setCatalog($data['table_catalog'] ?? null)
            ->setType($data['table_type'] ?? null)
        ;
    }

    /**
     * Reads the COMMENT ON TABLE / COMMENT ON COLUMN values of the given tables.
     *
     * Returns an empty map when the propagation is turned off, so that no
     * description reaches the built metadata and no extra query is sent.
     *
     * @param string[] $nameSchemas
     * @param string[] $nameTables
     * @return array<string, array{table: string|null, columns: array<string, string>}>
     */
    private function loadDescriptions(array $nameSchemas, array $nameTables, bool $loadColumns): array
    {
        if (!$this->propagateDescriptions || !$nameTables) {
            return [];
        }

        $descriptions = [];
        foreach ($this->queryDescriptions($nameSchemas, $nameTables, $loadColumns) as $row) {
            $tableId = $row['table_schema'] . '.' . $row['table_name'];
            if (!isset($descriptions[$tableId])) {
                $descriptions[$tableId] = ['table' => null, 'columns' => []];
            }

            if ($row['table_comment'] !== null) {
                $descriptions[$tableId]['table'] = (string) $row['table_comment'];
            }

            if ($loadColumns && $row['column_name'] !== null && $row['column_comment'] !== null) {
                $columnName = (string) $row['column_name'];
                $descriptions[$tableId]['columns'][$columnName] = (string) $row['column_comment'];
            }
        }

        return $descriptions;
    }

    /**
     * Comments are read by a query of their own instead of being joined into the
     * table and column queries, so that those keep returning exactly what they
     * returned before and cannot start duplicating rows.
     *
     * @param string[] $nameSchemas
     * @param string[] $nameTables
     */
    private function queryDescriptions(array $nameSchemas, array $nameTables, bool $loadColumns): iterable
    {
        $select = [
            'ns.nspname AS table_schema',
            'cls.relname AS table_name',
            // The two argument form cannot mistake a comment of another object for a table one
            'OBJ_DESCRIPTION(cls.oid, \'pg_class\') AS table_comment',
        ];

        $sql = [];
        $sql[] = 'FROM pg_catalog.pg_class AS cls';
        $sql[] = 'INNER JOIN pg_catalog.pg_namespace AS ns ON ns.oid = cls.relnamespace';

        if ($loadColumns) {
            $select[] = 'att.attname AS column_name';
            $select[] = 'COL_DESCRIPTION(cls.oid, att.attnum) AS column_comment';

            // attnum > 0 leaves out the system columns, LEFT JOIN keeps tables
            // with no column of their own, eg. a late binding view
            $sql[] = 'LEFT OUTER JOIN pg_catalog.pg_attribute AS att';
            $sql[] = 'ON att.attrelid = cls.oid AND att.attnum > 0';
        }

        $sql[] = sprintf(
            'WHERE ns.nspname IN (%s) AND cls.relname IN (%s)',
            $this->quoteList($nameSchemas),
            $this->quoteList($nameTables),
        );

        array_unshift($sql, sprintf('SELECT %s', implode(', ', $select)));

        return $this->queryAndFetchAll(implode(' ', $sql));
    }

    /**
     * @param string[] $values
     */
    private function quoteList(array $values): string
    {
        return implode(
            ', ',
            array_map(fn (string $value): string => $this->db->quote($value), array_unique($values)),
        );
    }

    private function queryColumns(array $nameSchemas, array $nameTables): iterable
    {
        $sqlTemplate = <<<SQL
SELECT DISTINCT cols.column_name, cols.table_name, cols.table_schema, 
        cols.column_default, cols.is_nullable, cols.data_type, cols.ordinal_position,
        cols.character_maximum_length, cols.numeric_precision, cols.numeric_scale,
        def.contype, def.conkey
FROM information_schema.columns as cols 
JOIN (
  SELECT
    a.attnum,
    n.nspname,
    c.relname,
    a.attname AS colname,
    t.typname AS type,
    a.atttypmod,
    FORMAT_TYPE(a.atttypid, a.atttypmod) AS complete_type,
    d.adsrc AS default_value,
    a.attnotnull AS notnull,
    a.attlen AS length,
    co.contype,
    ARRAY_TO_STRING(co.conkey, ',') AS conkey
  FROM pg_attribute AS a
    JOIN pg_class AS c ON a.attrelid = c.oid
    JOIN pg_namespace AS n ON c.relnamespace = n.oid
    JOIN pg_type AS t ON a.atttypid = t.oid
    LEFT OUTER JOIN pg_constraint AS co ON (co.conrelid = c.oid
        AND a.attnum = ANY(co.conkey) AND (co.contype = 'p' OR co.contype = 'u'))
    LEFT OUTER JOIN pg_attrdef AS d ON d.adrelid = c.oid AND d.adnum = a.attnum
  WHERE a.attnum > 0 AND c.relname IN (%s)
) as def 
ON cols.column_name = def.colname AND cols.table_name = def.relname
WHERE cols.table_name IN (%s) AND cols.table_schema IN (%s)
ORDER BY cols.table_schema, cols.table_name, cols.ordinal_position
SQL;

        $sql = sprintf(
            $sqlTemplate,
            implode(', ', array_map(function (string $tableName) {
                return $this->db->quote($tableName);
            }, $nameTables)),
            implode(', ', array_map(function (string $tableName) {
                return $this->db->quote($tableName);
            }, $nameTables)),
            implode(
                ', ',
                array_map(fn (string $schemaName): string => $this->db->quote($schemaName), $nameSchemas),
            ),
        );

        return $this->queryAndFetchAll($sql);
    }

    private function queryLateBindViewsColumns(): iterable
    {
        $sql = <<<SQL
select * from pg_get_late_binding_view_cols()
    cols(view_schema name, view_name name, col_name name, col_type varchar, col_num int);
SQL;

        return $this->queryAndFetchAll($sql);
    }

    private function queryTables(?array $whiteList): iterable
    {
        $sql = [];
        $sql[] = 'SELECT * FROM information_schema.tables';
        $sql[] = 'WHERE table_schema != \'pg_catalog\'';
        $sql[] = 'AND table_schema != \'information_schema\'';
        $sql[] = 'AND table_schema != \'pg_internal\'';

        if ($whiteList) {
            $whiteListSql = array_map(function (InputTable $v) {
                return sprintf(
                    '(table_schema = %s AND table_name = %s)',
                    $this->db->quote($v->getSchema()),
                    $this->db->quote($v->getName()),
                );
            }, $whiteList);

            $sql[] = sprintf(
                'AND %s',
                implode(' OR ', $whiteListSql),
            );
        }

        $sql[] = 'ORDER BY table_schema, table_name';

        return $this->queryAndFetchAll(implode(' ', $sql));
    }

    private function processColumnData(ColumnBuilder $columnBuilder, array $column): void
    {
        $length = ($column['character_maximum_length']) ? $column['character_maximum_length'] : null;
        if (is_null($length) && !is_null($column['numeric_precision'])) {
            if ($column['numeric_scale'] > 0) {
                $length = $column['numeric_precision'] . ',' . $column['numeric_scale'];
            } else {
                $length = $column['numeric_precision'];
            }
        }
        $default = null;
        if (!is_null($column['column_default'])) {
            $default = str_replace("'", '', explode('::', $column['column_default'])[0]);
        }

        $columnBuilder
            ->setName($column['column_name'])
            ->setType($column['data_type'])
            ->setDefault($default)
            ->setLength((string) $length)
            ->setNullable((trim($column['is_nullable']) === 'NO') ? false : true)
            ->setOrdinalPosition((int) $column['ordinal_position'])
            ->setPrimaryKey(($column['contype'] === 'p') ? true : false)
            ->setUniqueKey(($column['contype'] === 'u') ? true : false)
        ;
    }

    private function queryAndFetchAll(string $sql): iterable
    {
        /** @var PDOStatement $result */
        $result = $this->db->query($sql);
        while ($row = $result->fetch()) {
            yield $row;
        }
    }
}
