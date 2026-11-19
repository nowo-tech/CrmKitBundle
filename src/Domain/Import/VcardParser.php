<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Domain\Import;

use function array_fill_keys;
use function array_map;
use function array_slice;
use function count;
use function explode;
use function implode;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

/**
 * Reads vCard 3.0 / 4.0 files (contract section 9) into rows with fixed columns: `FN`, `N`, `EMAIL`, `TEL`, `ORG`,
 * `TITLE`, `ADR` (first value of each); folded lines are unfolded, escapes decoded. Stateless.
 */
final class VcardParser
{
    /** The columns of a vCard import. */
    public const COLUMNS = ['first_name', 'last_name', 'email', 'phone', 'mobile', 'job_title', 'company', 'street', 'city', 'region', 'postal_code', 'country'];

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
        if (preg_match('//u', $bytes) !== 1) {
            throw new ImportFileException('import.error.encoding');
        }
        $text  = (string) preg_replace("/\r?\n[ \t]/", '', $bytes);
        $lines = preg_split('/\r?\n/', $text) ?: [];
        $rows  = [];
        $card  = null;
        foreach ($lines as $line) {
            $upper = strtoupper(trim($line));
            if ($upper === 'BEGIN:VCARD') {
                $card = [];

                continue;
            }
            if ($upper === 'END:VCARD') {
                if ($card !== null) {
                    if (count($rows) >= $maxRows) {
                        throw new ImportFileException('import.error.too_many_rows');
                    }
                    $rows[] = self::row($card);
                }
                $card = null;

                continue;
            }
            if ($card === null || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value]    = explode(':', $line, 2);
            $parts             = explode(';', $name);
            $property          = strtoupper((string) preg_replace('/^[^.]*\./', '', $parts[0]));
            $card[$property][] = ['params' => strtoupper(implode(';', array_slice($parts, 1))), 'value' => $value];
        }
        if ($rows === []) {
            throw new ImportFileException('import.error.empty');
        }

        return new ParsedFile(self::COLUMNS, $rows);
    }

    /**
     * One row from the properties of a card.
     *
     * @param array<string, list<array{params: string, value: string}>> $card
     *
     * @return array<string, string>
     */
    private static function row(array $card): array
    {
        $row               = array_fill_keys(self::COLUMNS, '');
        $n                 = self::parts($card['N'][0]['value'] ?? '');
        $row['last_name']  = $n[0] ?? '';
        $row['first_name'] = $n[1] ?? '';
        if ($row['first_name'] === '' && $row['last_name'] === '') {
            $row['last_name'] = self::unescape($card['FN'][0]['value'] ?? '');
        }
        $row['email'] = self::unescape($card['EMAIL'][0]['value'] ?? '');
        foreach ($card['TEL'] ?? [] as $tel) {
            $key = stripos($tel['params'], 'CELL') !== false ? 'mobile' : 'phone';
            if ($row[$key] === '') {
                $row[$key] = self::unescape(str_replace('tel:', '', $tel['value']));
            }
        }
        $row['job_title']   = self::unescape($card['TITLE'][0]['value'] ?? '');
        $row['company']     = self::parts($card['ORG'][0]['value'] ?? '')[0] ?? '';
        $adr                = self::parts($card['ADR'][0]['value'] ?? '');
        $row['street']      = $adr[2] ?? '';
        $row['city']        = $adr[3] ?? '';
        $row['region']      = $adr[4] ?? '';
        $row['postal_code'] = $adr[5] ?? '';
        $country            = $adr[6] ?? '';
        $row['country']     = preg_match('/\A[A-Za-z]{2}\z/D', $country) === 1 ? strtoupper($country) : '';

        return $row;
    }

    /**
     * The `;`-separated components of a structured value, unescaped.
     *
     * @return list<string>
     */
    private static function parts(string $value): array
    {
        $parts = preg_split('/(?<!\\\\);/', $value) ?: [];

        return array_map(static fn (string $p): string => self::unescape($p), $parts);
    }

    private static function unescape(string $value): string
    {
        return trim(str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value));
    }
}
