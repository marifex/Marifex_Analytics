<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string) file_get_contents($root . '/marifex.xml'));
if (!preg_match('/<version>([^<]+)<\/version>/', $version, $match)) {
    throw new RuntimeException('Plugin version is unavailable.');
}
$version = $match[1];
$outputDirectory = $root . '/versions/' . $version;
$zipPath = $outputDirectory . '/marifex-' . $version . '.zip';
$directories = ['locales', 'public', 'src', 'templates', 'vendor'];
$files = ['composer.json', 'hook.php', 'LICENSE', 'marifex.xml', 'README.md', 'setup.php'];

if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create the version archive directory.');
}
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Unable to create the plugin ZIP.');
}
foreach ($files as $file) $zip->addFile($root . '/' . $file, 'marifex/' . $file);
foreach ($directories as $directory) {
    $base = $root . '/' . $directory;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if (!$item->isFile()) continue;
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        $zip->addFile($item->getPathname(), 'marifex/' . $relative);
    }
}
$zip->close();

$verify = new ZipArchive();
if ($verify->open($zipPath) !== true) throw new RuntimeException('Unable to verify the plugin ZIP.');
for ($index = 0; $index < $verify->numFiles; $index++) {
    $name = (string) $verify->getNameIndex($index);
    if (str_contains($name, '\\') || !str_starts_with($name, 'marifex/')) {
        throw new RuntimeException('ZIP entry is not a portable POSIX plugin path: ' . $name);
    }
}
if ($verify->locateName('marifex/setup.php') === false) throw new RuntimeException('ZIP is missing marifex/setup.php.');
$entries = $verify->numFiles;
$verify->close();
printf("%s\nSHA256 %s\nEntries %d\n", $zipPath, hash_file('sha256', $zipPath), $entries);
