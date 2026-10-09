<?php

namespace justinholtweb\tidytags\console;

use craft\helpers\Console;
use craft\helpers\Json;
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
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map([$this, 'csvCell'], $row), ',', '"', '');
        }
        rewind($handle);
        $this->stdout((string)stream_get_contents($handle));
        fclose($handle);
    }

    /**
     * Neutralises spreadsheet formulas: titles are typed by editors, and a
     * cell starting with `=`, `+`, `-`, `@`, tab or CR runs as a formula when
     * the CSV is opened in Excel or Sheets. Such cells get a leading `'`.
     */
    protected function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        $value = (string)$value;
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'" . $value
            : $value;
    }
}
