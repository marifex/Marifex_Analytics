<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Metric;

final readonly class MetricDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public string $source,
        public string $format,
    ) {
    }
}

