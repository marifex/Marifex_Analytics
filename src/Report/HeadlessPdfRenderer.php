<?php

declare(strict_types=1);

namespace GlpiPlugin\Marifex\Report;

use Config;
use RuntimeException;

final class HeadlessPdfRenderer
{
    public function __construct(private readonly ReportFileStore $store = new ReportFileStore())
    {
    }

    public function render(string $html, string $pdfPath): void
    {
        $browser = $this->browserPath();
        if ($browser === null) {
            throw new RuntimeException('No supported headless Chrome or Edge executable is configured.');
        }
        $htmlPath = $this->store->path('html');
        file_put_contents($htmlPath, $html, LOCK_EX);
        try {
            $uri = 'file:///' . str_replace(['\\', ' '], ['/', '%20'], $htmlPath);
            $command = [
                $browser,
                '--headless=new',
                '--disable-gpu',
                '--disable-extensions',
                '--disable-background-networking',
                '--no-pdf-header-footer',
                '--print-to-pdf-no-header',
                '--print-to-pdf=' . $pdfPath,
                $uri,
            ];
            $this->run($command);
            if (!is_file($pdfPath) || filesize($pdfPath) < 1000) {
                throw new RuntimeException('The headless browser did not produce a valid PDF report.');
            }
        } finally {
            if (is_file($htmlPath)) {
                unlink($htmlPath);
            }
        }
    }

    /** @param list<string> $command */
    private function run(array $command): void
    {
        $pipes = [];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the headless browser process.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = ''; $error = ''; $deadline = microtime(true) + 60;
        $timedOut = false;
        do {
            $status = proc_get_status($process);
            $output .= stream_get_contents($pipes[1]);
            $error .= stream_get_contents($pipes[2]);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(100000);
        } while (true);
        fclose($pipes[1]); fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($timedOut) {
            throw new RuntimeException('The headless browser exceeded the 60 second PDF timeout.');
        }
        if ($exitCode !== 0 && ($status['exitcode'] ?? -1) !== 0) {
            throw new RuntimeException('Headless PDF rendering failed: ' . mb_substr(trim($error ?: $output), 0, 1000));
        }
    }

    /** @return array{available: bool, path: string|null} */
    public function status(): array
    {
        $path = $this->browserPath();
        return ['available' => $path !== null, 'path' => $path];
    }

    public function browserPath(): ?string
    {
        $config = Config::getConfigurationValues('plugin:marifex');
        $configured = trim((string) ($config['headless_browser_path'] ?? ''));
        $candidates = array_filter([
            $configured,
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ]);
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
