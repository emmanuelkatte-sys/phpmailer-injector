<?php

declare(strict_types=1);

namespace Warship\Injector\Recipient;

final class Recipient
{
    /** @param array<string, string> $customFields */
    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
        public readonly string $firstName = '',
        public readonly string $lastName = '',
        public readonly array $customFields = [],
        public readonly int $lineNumber = 0,
        public readonly int $index = 0,
        public readonly bool $isBacktest = false,
    ) {
    }
}

final class CsvReader implements \IteratorAggregate
{
    /** @var list<string>|null */
    private ?array $headers = null;

    public function __construct(
        private readonly string $path,
        private readonly string $delimiter = ',',
        private readonly bool $hasHeader = true,
        private readonly int $skipLines = 0,
        private readonly int $shardIndex = 0,
        private readonly int $shardCount = 1,
    ) {
    }

    public function count(): int
    {
        $count = 0;
        foreach ($this as $_) {
            $count++;
        }
        return $count;
    }

    public function countAll(): int
    {
        if ($this->shardCount <= 1) {
            return $this->count();
        }
        $all = new self($this->path, $this->delimiter, $this->hasHeader, $this->skipLines, 0, 1);
        return $all->count();
    }

    public function getIterator(): \Generator
    {
        $this->headers = null;
        $fh = fopen($this->path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open recipients file: {$this->path}");
        }

        $lineNo = 0;
        $skipped = 0;
        $emailIndex = 0;
        $shards = max(1, $this->shardCount);
        $shard = min(max(0, $this->shardIndex), $shards - 1);
        try {
            while (($row = fgetcsv($fh, 0, $this->delimiter)) !== false) {
                $lineNo++;
                if ($row === [null] || $row === []) {
                    continue;
                }

                if ($this->hasHeader && $this->headers === null) {
                    $this->headers = array_map(static fn ($h) => trim((string) $h), $row);
                    continue;
                }

                if ($skipped < $this->skipLines) {
                    $skipped++;
                    continue;
                }

                $email = trim((string) ($row[0] ?? ''));
                if ($email === '' || str_contains($email, "\r") || str_contains($email, "\n")) {
                    continue;
                }

                $name = trim((string) ($row[1] ?? ''));
                $first = trim((string) ($row[2] ?? ''));
                $last = trim((string) ($row[3] ?? ''));

                $custom = [];
                if ($this->headers !== null) {
                    for ($i = 4, $n = count($this->headers); $i < $n; $i++) {
                        $key = $this->headers[$i] ?? '';
                        if ($key !== '') {
                            $custom[$key] = trim((string) ($row[$i] ?? ''));
                        }
                    }
                }

                $idx = $emailIndex;
                $emailIndex++;
                if ($shards > 1 && ($idx % $shards) !== $shard) {
                    continue;
                }
                yield new Recipient($email, $name, $first, $last, $custom, $lineNo, $idx);
            }
        } finally {
            fclose($fh);
        }
    }
}

final class EmailListReader
{
    /** @return list<string> */
    public static function read(string $path): array
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Email list not found: {$path}");
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException("Cannot read email list: {$path}");
        }

        $out = [];
        $first = true;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($first) {
                $line = ltrim($line, "\xEF\xBB\xBF");
                $first = false;
            }
            $line = str_replace(["\r", "\n"], ' ', $line);
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
