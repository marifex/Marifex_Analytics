<?php
/*
 * Copyright (C) 2026 MarifeX
 *
 * This file is part of MarifeX Advanced Analytics.
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failed = false;

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $command = sprintf('%s -l %s', escapeshellarg(PHP_BINARY), escapeshellarg($file->getPathname()));
    exec($command, $output, $status);
    if ($status !== 0) {
        $failed = true;
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
    }
    $output = [];
}

exit($failed ? 1 : 0);

