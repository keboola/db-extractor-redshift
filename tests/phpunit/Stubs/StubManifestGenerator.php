<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Tests\Stubs;

use Keboola\DbExtractor\Adapter\ValueObject\ExportResult;
use Keboola\DbExtractor\Manifest\ManifestGenerator;
use Keboola\DbExtractorConfig\Configuration\ValueObject\ExportConfig;

/**
 * Returns a canned manifest, so the decorator can be tested without a database.
 */
class StubManifestGenerator implements ManifestGenerator
{
    /** @var array<string, mixed> */
    private array $manifest;

    private bool $legacy;

    /** @param array<string, mixed> $manifest */
    public function __construct(array $manifest)
    {
        $this->manifest = $manifest;
        $this->legacy = false;
    }

    public function generate(ExportConfig $exportConfig, ExportResult $exportResult, bool $legacy = false): array
    {
        $this->legacy = $legacy;
        return $this->manifest;
    }

    public function wasLegacy(): bool
    {
        return $this->legacy;
    }
}
