<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Manifest;

use Keboola\DbExtractor\Adapter\ValueObject\ExportResult;
use Keboola\DbExtractorConfig\Configuration\ValueObject\ExportConfig;

/**
 * Leaves only the base data type in the manifest.
 *
 * Redshift is no longer a supported Storage backend, so the platform rejects a
 * manifest that declares a "redshift" data type:
 *
 *     Failed to parse manifest file ... The "redshift" data type is not supported.
 *
 * The backend specific type is only ever produced for advanced query mode, where
 * DefaultManifestGenerator derives it from the extractor class. That method is
 * private, so the value is dropped from the finished manifest instead of being
 * prevented -- which keeps the whole of the library's own logic in play.
 *
 * Only the native format is touched. The legacy format has no notion of a data
 * type backend, the platform has never rejected it, and its metadata carries no
 * signal for which key came from where.
 */
class RedshiftManifestGenerator implements ManifestGenerator
{
    private const BACKEND = 'redshift';

    private ManifestGenerator $generator;

    public function __construct(ManifestGenerator $generator)
    {
        $this->generator = $generator;
    }

    public function generate(ExportConfig $exportConfig, ExportResult $exportResult, bool $legacy = false): array
    {
        $manifest = $this->generator->generate($exportConfig, $exportResult, $legacy);

        if (!isset($manifest['schema']) || !is_array($manifest['schema'])) {
            return $manifest;
        }

        $schema = [];
        foreach ($manifest['schema'] as $column) {
            if (is_array($column) && isset($column['data_type']) && is_array($column['data_type'])) {
                unset($column['data_type'][self::BACKEND]);
            }
            $schema[] = $column;
        }
        $manifest['schema'] = $schema;

        return $manifest;
    }
}
