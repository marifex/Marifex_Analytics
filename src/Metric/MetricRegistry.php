<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Metric;

use InvalidArgumentException;

final class MetricRegistry
{
    /** @var array<string, MetricDefinition> */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = [
            'current_open_tickets' => new MetricDefinition(
                'current_open_tickets',
                'Current open tickets',
                'live',
                'integer'
            ),
            'historical_open_backlog' => new MetricDefinition(
                'historical_open_backlog',
                'Historical open backlog',
                'data_mart',
                'time_series'
            ),
        ];
    }

    public function get(string $key): MetricDefinition
    {
        return $this->definitions[$key] ?? throw new InvalidArgumentException('Unknown metric.');
    }

    /** @return list<MetricDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}

