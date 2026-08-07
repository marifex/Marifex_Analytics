<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Security;

use RuntimeException;
use Session;

final class EntityScope
{
    /** @return list<int> */
    public function activeEntityIds(): array
    {
        if (Session::getLoginUserID() === false) {
            throw new RuntimeException('An authenticated GLPI session is required.');
        }

        $ids = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id >= 0)));

        if ($ids === []) {
            throw new RuntimeException('No active entity is available in the current GLPI session.');
        }

        return $ids;
    }

    /** @return array<string, list<int>> */
    public function criteria(string $field = 'entities_id'): array
    {
        return [$field => $this->activeEntityIds()];
    }
}

