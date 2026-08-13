<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Analytics;

final readonly class MonitoringScope
{
    /** @param list<int> $entityIds */
    public function __construct(
        public int $rootEntityId,
        public bool $recursive,
        public array $entityIds,
        public ?int $groupId,
        public string $metricKey,
        public string $grain,
    ) {
    }

    public function fingerprint(): string
    {
        $entityIds = array_values(array_unique(array_map('intval', $this->entityIds)));
        sort($entityIds, SORT_NUMERIC);

        return hash('sha256', json_encode([
            'metric' => $this->metricKey,
            'root_entity' => $this->rootEntityId,
            'recursive' => $this->recursive,
            'entity_scope' => $entityIds,
            'group_filter' => $this->groupId,
            'grain' => $this->grain,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
