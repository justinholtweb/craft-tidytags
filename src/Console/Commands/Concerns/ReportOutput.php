<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Console\Commands\Concerns;

use Illuminate\Console\Command;
use JustinHoltWeb\TidyTags\Support\Csv;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Output for the Tidy Tags commands. Reports go to stdout as a table (for people), JSON or CSV (for
 * scripts and CI). In JSON and CSV, every note goes to stderr, so stdout can be piped straight
 * into `jq` or a file.
 *
 * @mixin Command
 */
trait ReportOutput
{
    /** @var list<string> */
    private static array $formats = ['table', 'json', 'csv'];

    protected function format(): string
    {
        return (string) ($this->option('format') ?: 'table');
    }

    protected function checkFormat(): bool
    {
        if (! in_array($this->format(), self::$formats, true)) {
            $this->problem('--format must be one of: '.implode(', ', self::$formats).'.');

            return false;
        }

        return true;
    }

    protected function isMachineFormat(): bool
    {
        return $this->format() !== 'table';
    }

    /**
     * A note for people: stdout for tables, stderr for JSON and CSV.
     */
    protected function say(string $message, ?string $style = null): void
    {
        $line = $style !== null ? "<{$style}>{$message}</{$style}>" : $message;

        if ($this->isMachineFormat()) {
            $this->stderr($line);
        } else {
            $this->line($line);
        }
    }

    protected function problem(string $message): void
    {
        $this->stderr("<error>{$message}</error>");
    }

    protected function stderr(string $line): void
    {
        $output = $this->getOutput()->getOutput();
        ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($line);
    }

    protected function outputJson(mixed $data): void
    {
        $this->getOutput()->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int, array<int, scalar|null>>  $rows
     */
    protected function outputCsv(array $headers, array $rows): void
    {
        $this->getOutput()->write(Csv::build($headers, $rows), false, OutputInterface::OUTPUT_RAW);
    }
}
