<?php

namespace justinholtweb\tidytags\console;

use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\tidytags\helpers\Csv;
use yii\console\ExitCode;

/**
 * Shared output handling for the Tidy Tags console commands.
 *
 * Reports go to stdout as a table (for people), JSON or CSV (for scripts and
 * CI). When the format is machine-readable, every human-facing note goes to
 * stderr instead, so stdout can be piped straight into `jq` or a file.
 *
 * @mixin \craft\console\Controller
 */
trait ReportOutput
{
    /**
     * @var string Output format: `table`, `json` or `csv`.
     */
    public string $format = 'table';

    private const FORMATS = ['table', 'json', 'csv'];

    /**
     * Returns an exit code when the format option is invalid, otherwise null.
     */
    protected function checkFormat(): ?int
    {
        if (!in_array($this->format, self::FORMATS, true)) {
            $this->stderr('--format must be one of: ' . implode(', ', self::FORMATS) . ".\n", Console::FG_RED);
            return ExitCode::USAGE;
        }
        return null;
    }

    protected function isMachineFormat(): bool
    {
        return $this->format !== 'table';
    }

    /**
     * A note for people: stdout for tables, stderr for JSON/CSV.
     */
    protected function say(string $message, int ...$style): void
    {
        if ($this->isMachineFormat()) {
            $this->stderr($message . "\n", ...$style);
        } else {
            $this->stdout($message . "\n", ...$style);
        }
    }

    protected function fail(string $message): void
    {
        $this->stderr($message . "\n", Console::FG_RED);
    }

    protected function outputJson(mixed $data): void
    {
        $this->stdout(Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * @param string[] $headers
     * @param array<int, array<int, scalar|null>> $rows
     */
    protected function outputCsv(array $headers, array $rows): void
    {
        $this->stdout(Csv::build($headers, $rows));
    }

    /**
     * @see Csv::cell()
     */
    protected function csvCell(mixed $value): string
    {
        return Csv::cell($value);
    }
}
