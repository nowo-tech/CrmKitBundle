<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Domain;

use Nowo\CrmKitBundle\Domain\Import\CsvParser;
use Nowo\CrmKitBundle\Domain\Import\ImportFileException;
use Nowo\CrmKitBundle\Domain\Import\VcardParser;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * CSV and vCard parsing (contract section 9): text only, limits, BOM, separators, folded vCard lines.
 */
final class ImportParsersTest extends TestCase
{
    public function testCsvWithCommasAndQuotes(): void
    {
        $file = CsvParser::parse("\xEF\xBB\xBFname,email\n\"Ada, the first\",ada@x.test\n\nBob,\"=1+2\"\nShort\n", 10, 10000);
        self::assertSame(['name', 'email'], $file->columns);
        self::assertSame([['name' => 'Ada, the first', 'email' => 'ada@x.test'], ['name' => 'Bob', 'email' => '=1+2'], ['name' => 'Short', 'email' => '']], $file->rows);
    }

    public function testCsvWithSemicolons(): void
    {
        $file = CsvParser::parse("name;city\nAcme;Madrid\n", 10, 10000);
        self::assertSame([['name' => 'Acme', 'city' => 'Madrid']], $file->rows);
    }

    public function testCsvRefusals(): void
    {
        foreach ([
            'import.error.too_large'     => [str_repeat('a', 2000), 10, 100],
            'import.error.encoding'      => ["name\n\xC3", 10, 1000],
            'import.error.header'        => ["name,,email\nA,B,C\n", 10, 1000],
            'import.error.too_many_rows' => ["name\nA\nB\n", 1, 1000],
            'import.error.empty'         => ["name\n", 10, 1000],
        ] as $key => [$bytes, $rows, $max]) {
            try {
                CsvParser::parse($bytes, $rows, $max);
                self::fail('accepted ' . $key);
            } catch (ImportFileException $e) {
                self::assertSame($key, $e->getMessage());
            }
        }
        $this->expectExceptionMessage('import.error.header');
        CsvParser::parse("name,name\nA,B\n", 10, 1000);
    }

    public function testVcards(): void
    {
        $vcf  = "\xEF\xBB\xBFBEGIN:VCARD\r\nVERSION:4.0\r\nN:Lovelace;Ada;;;\r\nFN:Ada Lovelace\r\nitem1.EMAIL;TYPE=work:ada@x.test\r\nTEL;TYPE=cell:tel:+34 600\r\nTEL;TYPE=work:+34 91\r\nTEL:+34 92\r\nTITLE:Count\\, Engineer\r\nORG:Analytical\; Engines;R&D\r\nADR:;;Main St 1;Lon\r\n don;;N1;gb\r\nNOTE no colon\r\nEND:VCARD\r\nBEGIN:VCARD\r\nFN:Bob Only\r\nADR:;;;;;;Spain\r\nEND:VCARD\r\nEND:VCARD\r\nX:outside\r\n";
        $file = VcardParser::parse($vcf, 10, 10000);
        self::assertSame(VcardParser::COLUMNS, $file->columns);
        self::assertSame('Ada', $file->rows[0]['first_name']);
        self::assertSame('Lovelace', $file->rows[0]['last_name']);
        self::assertSame('ada@x.test', $file->rows[0]['email']);
        self::assertSame('+34 600', $file->rows[0]['mobile']);
        self::assertSame('+34 91', $file->rows[0]['phone']);
        self::assertSame('Count, Engineer', $file->rows[0]['job_title']);
        self::assertSame('Analytical; Engines', $file->rows[0]['company']);
        self::assertSame('London', $file->rows[0]['city']);
        self::assertSame('GB', $file->rows[0]['country']);
        self::assertSame('Bob Only', $file->rows[1]['last_name']);
        self::assertSame('', $file->rows[1]['country']);
    }

    public function testVcardRefusals(): void
    {
        foreach ([
            'import.error.too_large'     => [str_repeat('a', 200), 10, 100],
            'import.error.encoding'      => ["BEGIN:VCARD\n\xC3", 10, 1000],
            'import.error.too_many_rows' => ["BEGIN:VCARD\nFN:A\nEND:VCARD\nBEGIN:VCARD\nFN:B\nEND:VCARD\n", 1, 1000],
            'import.error.empty'         => ["FN:A\n", 10, 1000],
        ] as $key => [$bytes, $rows, $max]) {
            try {
                VcardParser::parse($bytes, $rows, $max);
                self::fail('accepted ' . $key);
            } catch (ImportFileException $e) {
                self::assertSame($key, $e->getMessage());
            }
        }
    }
}
