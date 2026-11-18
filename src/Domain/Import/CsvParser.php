<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Import;

use SplTempFileObject;

use function array_keys;
use function count;
use function is_array;
use function preg_match;
use function preg_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function substr_count;
use function trim;

/**
 * Reads a CSV import (contract section 9): UTF-8 (BOM stripped), `,` or `;` (the one the header uses more), a header
 * row, at most `$maxRows` rows and `$maxBytes` bytes. Cells are text only (nothing is evaluated). Stateless.
 */
final class CsvParser
{
    /**
     * Parses the bytes.
     *
     * @throws ImportFileException
     */
    public static function parse(string $bytes, int $maxRows, int $maxBytes): ParsedFile
    {
        if (strlen($bytes) > $maxBytes) {
            throw new ImportFileException('import.error.too_large');
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        if (trim($bytes) === '' || preg_match('//u', $bytes) !== 1) {
            throw new ImportFileException('import.error.encoding');
        }
        $firstLine = (string) preg_replace('/\R.*/s', '', $bytes);
        $separator = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $file      = new SplTempFileObject();
        $file->fwrite($bytes);
        $file->rewind();
        $columns = [];
        foreach ((array) $file->fgetcsv($separator, '"', '') as $column) {
            $name = trim((string) $column);
            if ($name === '' || isset($columns[$name])) {
                throw new ImportFileException('import.error.header');
            }
            $columns[$name] = true;
        }
        $names = array_keys($columns);
        $rows  = [];
        while (!$file->eof()) {
            $cells = $file->fgetcsv($separator, '"', '');
            if (!is_array($cells) || $cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue;
            }
            if (count($rows) >= $maxRows) {
                throw new ImportFileException('import.error.too_many_rows');
            }
            $row = [];
            foreach ($names as $i => $name) {
                $row[$name] = (string) ($cells[$i] ?? '');
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            throw new ImportFileException('import.error.empty');
        }

        return new ParsedFile($names, $rows);
    }
}
