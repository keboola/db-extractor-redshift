<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Tests;

use Keboola\DbExtractor\Adapter\ValueObject\ExportResult;
use Keboola\DbExtractor\Manifest\RedshiftManifestGenerator;
use Keboola\DbExtractor\Tests\Stubs\StubManifestGenerator;
use Keboola\DbExtractorConfig\Configuration\ValueObject\ExportConfig;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class RedshiftManifestGeneratorTest extends TestCase
{
    public function testBackendDataTypeIsDropped(): void
    {
        $manifest = $this->generate([
            'destination' => 'in.c-main.escaping',
            'schema' => [
                $this->column('col1', ['base' => ['type' => 'STRING'], 'redshift' => ['type' => 'varchar']]),
                $this->column('col2', ['base' => ['type' => 'STRING'], 'redshift' => ['type' => 'varchar']]),
            ],
        ]);

        Assert::assertSame([
            'destination' => 'in.c-main.escaping',
            'schema' => [
                $this->column('col1', ['base' => ['type' => 'STRING']]),
                $this->column('col2', ['base' => ['type' => 'STRING']]),
            ],
        ], $manifest);
    }

    public function testEverythingElseIsLeftAlone(): void
    {
        $input = [
            'destination' => 'in.c-main.escaping',
            'incremental' => false,
            'description' => 'Table level comment',
            'table_metadata' => ['KBC.name' => 'escaping'],
            'schema' => [
                [
                    'name' => 'col1',
                    'nullable' => true,
                    'primary_key' => false,
                    'description' => 'Some column',
                    'metadata' => ['KBC.sourceName' => 'col1'],
                    'data_type' => ['base' => ['type' => 'STRING'], 'redshift' => ['type' => 'varchar']],
                ],
            ],
        ];

        $manifest = $this->generate($input);

        $expected = $input;
        unset($expected['schema'][0]['data_type']['redshift']);
        Assert::assertSame($expected, $manifest);
    }

    /**
     * A manifest with only the base type must come through untouched, so that
     * table mode -- which never had a backend type -- keeps its exact output.
     */
    public function testManifestWithoutBackendTypeIsUnchanged(): void
    {
        $input = [
            'destination' => 'in.c-main.sales',
            'schema' => [$this->column('usergender', ['base' => ['type' => 'STRING']])],
        ];

        Assert::assertSame($input, $this->generate($input));
    }

    /**
     * The legacy format has no data type backends and the platform has never
     * rejected it, so it must not be rewritten.
     */
    public function testLegacyManifestIsUnchanged(): void
    {
        $input = [
            'destination' => 'in.c-main.escaping',
            'columns' => ['col1'],
            'column_metadata' => [
                'col1' => [
                    ['key' => 'KBC.datatype.basetype', 'value' => 'STRING'],
                    ['key' => 'KBC.datatype.type', 'value' => 'varchar'],
                ],
            ],
        ];

        Assert::assertSame($input, $this->generate($input, true));
    }

    public function testLegacyFlagIsPassedThrough(): void
    {
        $inner = new StubManifestGenerator(['destination' => 'in.c-main.escaping']);
        (new RedshiftManifestGenerator($inner))->generate(
            $this->createStub(ExportConfig::class),
            $this->createStub(ExportResult::class),
            true,
        );

        Assert::assertTrue($inner->wasLegacy());
    }

    /**
     * @param array<string, mixed> $dataTypes
     * @return array<string, mixed>
     */
    private function column(string $name, array $dataTypes): array
    {
        return [
            'name' => $name,
            'nullable' => true,
            'primary_key' => false,
            'data_type' => $dataTypes,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function generate(array $manifest, bool $legacy = false): array
    {
        return (new RedshiftManifestGenerator(new StubManifestGenerator($manifest)))->generate(
            $this->createStub(ExportConfig::class),
            $this->createStub(ExportResult::class),
            $legacy,
        );
    }
}
