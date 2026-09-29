<?php

declare(strict_types=1);

namespace Warship\Injector\Report;

final class Reporter
{
    public function __construct(
        private readonly string $progressFile,
        private readonly string $resultFile,
        private readonly string $errorFile,
    ) {
        $this->ensureDir($this->progressFile);
        $this->ensureDir($this->resultFile);
        $this->ensureDir($this->errorFile);
    }

    /** @param array<string, mixed> $progress */
    public function updateProgress(array $progress): void
    {
        if ($this->progressFile === '') {
            return;
        }
        $this->writeJsonAtomic($this->progressFile, $progress);
    }

    /** @param array<string, mixed> $result @param list<array{email:string,error:string,line:int}> $errors */
    public function saveResult(array $result, array $errors = []): void
    {
        if ($this->resultFile !== '') {
            $this->writeJsonAtomic($this->resultFile, $result);
        }
        if ($errors !== [] && $this->errorFile !== '') {
            $lines = [];
            foreach ($errors as $e) {
                $lines[] = sprintf(
                    'Line %d: %s - %s',
                    $e['line'] ?? 0,
                    $e['email'] ?? '',
                    $e['error'] ?? ''
                );
            }
            file_put_contents($this->errorFile, implode(PHP_EOL, $lines) . PHP_EOL);
        }
    }

    private function ensureDir(string $file): void
    {
        if ($file === '') {
            return;
        }
        $dir = dirname($file);
        if ($dir !== '' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /** @param array<string, mixed> $data */
    private function writeJsonAtomic(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $json);
        rename($tmp, $path);
    }
}
