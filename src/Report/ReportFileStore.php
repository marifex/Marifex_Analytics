<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use RuntimeException;

final class ReportFileStore
{
    public function root(): string
    {
        $root = GLPI_PLUGIN_DOC_DIR . DIRECTORY_SEPARATOR . 'marifex' . DIRECTORY_SEPARATOR . 'reports';
        if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create the protected report directory.');
        }
        return $root;
    }

    public function path(string $extension): string
    {
        return $this->root() . DIRECTORY_SEPARATOR . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    public function isManaged(string $path): bool
    {
        $root = realpath($this->root());
        $parent = realpath(dirname($path));
        return $root !== false && $parent !== false && ($parent === $root || str_starts_with($parent, $root . DIRECTORY_SEPARATOR));
    }
}
