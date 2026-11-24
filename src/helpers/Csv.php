<?php

namespace justinholtweb\tidytags\helpers;

/**
 * CSV building shared by the console reports and the control panel's URL
 * report download.
 */
final class Csv
{
    /**
     * Builds a CSV document from a header row and data rows.
     *
     * @param string[] $headers
     * @param array<int, array<int, scalar|null>> $rows
     */
    public static function build(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map([self::class, 'cell'], $row), ',', '"', '');
        }
        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Neutralises spreadsheet formulas: titles are typed by editors, and a
     * cell starting with `=`, `+`, `-`, `@`, tab or CR runs as a formula when
     * the CSV is opened in Excel or Sheets. Such cells get a leading `'`.
     */
    public static function cell(mixed $value): string
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
