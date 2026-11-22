<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database;

use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Read\NoteHit;
use Nowo\CrmKitBundle\Domain\Read\RecordView;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\ListQuery;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;

use function array_map;
use function sort;

/**
 * Audit YN-L1 (0.6.0): every text search lowers the column and the parameter in SQL (`LOWER(col) LIKE LOWER(:q)`), so a
 * capital letter outside ASCII ("ÉCOLE" for "École") finds the text on SQLite, MySQL, MariaDB and PostgreSQL.
 */
final class SearchCaseTest extends CrmTestCase
{
    public function testRecordSearchFindsNonAsciiTextWhateverTheCase(): void
    {
        $this->boot();
        $this->admin();
        $byLegal = $this->company('Colegio Norte', ['legal_name' => 'École Maternelle SL']);
        $byName  = $this->company('École Primaire');
        $byEmail = $this->company('Mail Co', ['email' => 'Info@Ecole.Example']);
        $this->company('Other');
        $contact = $this->contact('Émile', 'Zola', 'Emile@Zola.Example');
        $this->contact('Ada', 'Lovelace');

        foreach (['ÉCOLE', 'École', 'école'] as $text) {
            $found = $this->labels(new ListQuery(SubjectType::Company, text: $text));
            // SQLite's LOWER() folds ASCII only: there a lower-case "é" does not meet the stored "É" (as before 0.6.0).
            if ($text !== 'école' || $this->platform() !== 'sqlite') {
                self::assertContains('Colegio Norte', $found, $text . ' (legal name, LOWER in SQL)');
            }
            self::assertContains('École Primaire', $found, $text . ' (name key)');
        }
        self::assertContains('Mail Co', $this->labels(new ListQuery(SubjectType::Company, text: 'INFO@ECOLE')), 'the e-mail as typed');
        self::assertSame([], $this->labels(new ListQuery(SubjectType::Company, text: 'Écoles')), 'no false hit');
        self::assertSame(['Émile Zola'], $this->labels(new ListQuery(SubjectType::Contact, text: 'ÉMILE')));
        self::assertSame(['Émile Zola'], $this->labels(new ListQuery(SubjectType::Contact, text: 'ZOLA')));
        self::assertNotSame('', $byLegal . $byName . $byEmail . $contact);
    }

    public function testNoteSearchFindsNonAsciiTextWhateverTheCase(): void
    {
        $this->boot();
        $this->admin();
        $acme = $this->company('Acme');
        $this->service(NoteServiceInterface::class)->add(SubjectType::Company, $acme, 'Visita a la École del barrio');
        $reader = $this->service(CrmReaderInterface::class);
        foreach (['ÉCOLE', 'École'] as $text) {
            self::assertCount(1, $reader->searchNotes($text, 10), $text);
        }
        self::assertSame(['Visita a la École del barrio'], array_map(static fn (NoteHit $h): string => $h->note->body, $reader->searchNotes('ÉCOLE DEL', 10)));
    }

    /**
     * @return list<string>
     */
    private function labels(ListQuery $query): array
    {
        $labels = array_map(static fn (RecordView $row): string => $row->label, $this->service(CrmReaderInterface::class)->list($query)->rows);
        sort($labels);

        return $labels;
    }
}
