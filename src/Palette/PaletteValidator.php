<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Palette;

use InvalidArgumentException;

final class PaletteValidator
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function validate(array $input): array
    {
        $allowed = ['schemaVersion','name','type','colors','baseColor','slotCount','lightnessSpan','areaOpacity','isRecursive'];
        if (array_diff(array_keys($input), $allowed) !== []) throw new InvalidArgumentException('Palette contains unknown fields.');
        if (($input['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) throw new InvalidArgumentException('Unsupported palette schemaVersion.');
        $name = class_exists(\Normalizer::class) ? \Normalizer::normalize(trim((string) ($input['name'] ?? '')), \Normalizer::FORM_C) : trim((string) ($input['name'] ?? ''));
        if (!is_string($name) || mb_strlen($name) < 1 || mb_strlen($name) > 50 || !preg_match('/^[\p{L}\p{N} _-]+$/u', $name)) throw new InvalidArgumentException('Palette name is invalid.');
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, ['categorical','monochrome','gradient'], true)) throw new InvalidArgumentException('Palette type is invalid.');
        $opacity = $input['areaOpacity'] ?? null;
        if (!is_numeric($opacity) || (float) $opacity < .15 || (float) $opacity > .60) throw new InvalidArgumentException('Area opacity must be between 0.15 and 0.60.');
        if (!is_bool($input['isRecursive'] ?? null)) throw new InvalidArgumentException('isRecursive must be boolean.');
        $definition = ['schemaVersion' => 1, 'name' => $name, 'type' => $type, 'areaOpacity' => (float) $opacity, 'isRecursive' => $input['isRecursive']];
        if ($type === 'monochrome') {
            $base = (string) ($input['baseColor'] ?? ''); $slots = $input['slotCount'] ?? null; $span = $input['lightnessSpan'] ?? null;
            $this->hex($base);
            if (!is_int($slots) || $slots < 6 || $slots > 12) throw new InvalidArgumentException('Monochrome slotCount must be 6 to 12.');
            if (!is_numeric($span) || (float) $span < 24 || (float) $span > 64) throw new InvalidArgumentException('Monochrome lightnessSpan must be 24 to 64.');
            if (isset($input['colors']) && $input['colors'] !== null) throw new InvalidArgumentException('Monochrome palettes cannot contain colors.');
            return $definition + ['baseColor' => strtoupper($base), 'slotCount' => $slots, 'lightnessSpan' => (float) $span];
        }
        if (isset($input['baseColor']) && $input['baseColor'] !== null || isset($input['slotCount']) && $input['slotCount'] !== null || isset($input['lightnessSpan']) && $input['lightnessSpan'] !== null) throw new InvalidArgumentException('Only monochrome palettes accept generation fields.');
        $colors = $input['colors'] ?? null;
        $minimum = $type === 'categorical' ? 6 : 2;
        if (!is_array($colors) || count($colors) < $minimum || count($colors) > 12) throw new InvalidArgumentException(sprintf('%s palettes require %d to 12 colors.', ucfirst($type), $minimum));
        $colors = array_values(array_map(function ($color): string { $color = (string) $color; $this->hex($color); return strtoupper($color); }, $colors));
        if ($type === 'categorical' && count(array_unique($colors)) !== count($colors)) throw new InvalidArgumentException('Categorical palette colors must be distinct.');
        return $definition + ['colors' => $colors];
    }

    public function assertImport(string $json): array
    {
        if (strlen($json) > 51200) throw new InvalidArgumentException('Palette JSON exceeds 50 KB.');
        if (preg_match('/(?:<script|javascript:|https?:|url\s*\()/i', $json)) throw new InvalidArgumentException('Palette JSON contains prohibited script or URL content.');
        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"\s*:/', $json, $keys) === false) throw new InvalidArgumentException('Palette JSON could not be inspected.');
        $fieldNames = $keys[1] ?? [];
        if (count($fieldNames) !== count(array_unique($fieldNames))) throw new InvalidArgumentException('Palette JSON contains duplicate keys.');
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('Palette JSON must be an object.');
        return $this->validate($decoded);
    }

    private function hex(string $value): void
    {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) throw new InvalidArgumentException('Palette colors must use #RRGGBB hex values.');
    }
}
