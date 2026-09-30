<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Tests;

use Keboola\DbExtractor\Extractor\RedshiftMetadataProvider;
use Keboola\DbExtractor\Tests\Stubs\CapturingRedshiftPdoConnection;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class RedshiftMetadataProviderTest extends TestCase
{
    public function testDescriptionsArePropagated(): void
    {
        $connection = $this->createConnection([
            $this->descriptionRow('Registered users', 'id', 'Surrogate key'),
            $this->descriptionRow('Registered users', 'email', null),
        ]);

        $table = (new RedshiftMetadataProvider($connection, true))
            ->listTables()
            ->getByNameAndSchema('users', 'public');

        Assert::assertTrue($table->hasDescription());
        Assert::assertSame('Registered users', $table->getDescription());

        $columns = $table->getColumns();
        Assert::assertSame('Surrogate key', $columns->getByName('id')->getDescription());
        // A column without a COMMENT must not get a description
        Assert::assertFalse($columns->getByName('email')->hasDescription());
    }

    public function testDescriptionsAreNotPropagatedWhenDisabled(): void
    {
        $connection = $this->createConnection([
            $this->descriptionRow('Registered users', 'id', 'Surrogate key'),
        ]);

        $table = (new RedshiftMetadataProvider($connection, false))
            ->listTables()
            ->getByNameAndSchema('users', 'public');

        Assert::assertFalse($table->hasDescription());
        Assert::assertFalse($table->getColumns()->getByName('id')->hasDescription());
        Assert::assertFalse($connection->hasQueryContaining(CapturingRedshiftPdoConnection::DESCRIPTIONS_MARKER));
    }

    public function testEmptyCommentIsTreatedAsNoDescription(): void
    {
        $connection = $this->createConnection([
            // COMMENT ON ... IS '' must not become an empty description
            $this->descriptionRow('', 'id', '   '),
        ]);

        $table = (new RedshiftMetadataProvider($connection, true))
            ->listTables()
            ->getByNameAndSchema('users', 'public');

        Assert::assertFalse($table->hasDescription());
        Assert::assertFalse($table->getColumns()->getByName('id')->hasDescription());
    }

    public function testCommentsOfAnotherTableAreNotUsed(): void
    {
        // Same table name in another schema, must not leak into "public"
        $otherSchema = ['table_schema' => 'other', 'table_name' => 'users']
            + $this->descriptionRow('Other users', 'id', 'Other key');

        $connection = $this->createConnection([$otherSchema]);

        $table = (new RedshiftMetadataProvider($connection, true))
            ->listTables()
            ->getByNameAndSchema('users', 'public');

        Assert::assertFalse($table->hasDescription());
        Assert::assertFalse($table->getColumns()->getByName('id')->hasDescription());
    }

    public function testDescriptionsQueryIsScopedToTheListedTables(): void
    {
        $connection = $this->createConnection([]);
        (new RedshiftMetadataProvider($connection, true))->listTables();

        $sql = $connection->getQueryContaining(CapturingRedshiftPdoConnection::DESCRIPTIONS_MARKER);
        Assert::assertStringContainsString("OBJ_DESCRIPTION(cls.oid, 'pg_class')", $sql);
        Assert::assertStringContainsString('COL_DESCRIPTION(cls.oid, att.attnum)', $sql);
        Assert::assertStringContainsString("WHERE ns.nspname IN ('public') AND cls.relname IN ('users')", $sql);
    }

    public function testOnlyTableCommentsAreReadWithoutColumns(): void
    {
        $connection = $this->createConnection([
            $this->descriptionRow('Registered users', 'id', 'Surrogate key'),
        ]);

        $table = (new RedshiftMetadataProvider($connection, true))
            ->listTables([], false)
            ->getByNameAndSchema('users', 'public');

        Assert::assertSame('Registered users', $table->getDescription());

        $sql = $connection->getQueryContaining(CapturingRedshiftPdoConnection::DESCRIPTIONS_MARKER);
        Assert::assertStringNotContainsString('COL_DESCRIPTION', $sql);
        Assert::assertStringNotContainsString('pg_attribute', $sql);
    }

    /**
     * The table and column queries must stay exactly as they were, whether the
     * propagation is on or off, so that no existing configuration changes.
     *
     * @dataProvider propagateDescriptionsProvider
     */
    public function testTableAndColumnQueriesAreUntouched(bool $propagateDescriptions): void
    {
        $connection = $this->createConnection([]);
        (new RedshiftMetadataProvider($connection, $propagateDescriptions))->listTables();

        foreach ([
            CapturingRedshiftPdoConnection::TABLES_MARKER,
            CapturingRedshiftPdoConnection::COLUMNS_MARKER,
        ] as $marker) {
            $sql = $connection->getQueryContaining($marker);
            Assert::assertStringNotContainsStringIgnoringCase('description', $sql);
            Assert::assertStringNotContainsStringIgnoringCase('comment', $sql);
        }
    }

    public function propagateDescriptionsProvider(): array
    {
        return [
            'enabled' => [true],
            'disabled' => [false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function descriptionRow(?string $tableComment, string $columnName, ?string $columnComment): array
    {
        return [
            'table_schema' => 'public',
            'table_name' => 'users',
            'table_comment' => $tableComment,
            'column_name' => $columnName,
            'column_comment' => $columnComment,
        ];
    }

    /**
     * @param array<array<string, mixed>> $descriptions
     */
    private function createConnection(array $descriptions): CapturingRedshiftPdoConnection
    {
        $tables = [
            [
                'table_schema' => 'public',
                'table_name' => 'users',
                'table_catalog' => 'testdb',
                'table_type' => 'BASE TABLE',
            ],
        ];

        $columns = [
            $this->columnRow('id', 'integer', 1, 'NO'),
            $this->columnRow('email', 'character varying', 2, 'YES'),
        ];

        return new CapturingRedshiftPdoConnection($tables, $columns, $descriptions);
    }

    /**
     * @return array<string, mixed>
     */
    private function columnRow(string $name, string $type, int $ordinalPosition, string $isNullable): array
    {
        return [
            'column_name' => $name,
            'table_name' => 'users',
            'table_schema' => 'public',
            'column_default' => null,
            'is_nullable' => $isNullable,
            'data_type' => $type,
            'ordinal_position' => $ordinalPosition,
            'character_maximum_length' => null,
            'numeric_precision' => null,
            'numeric_scale' => null,
            'contype' => null,
            'conkey' => null,
        ];
    }
}
