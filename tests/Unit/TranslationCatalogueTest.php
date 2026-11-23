<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_diff;
use function array_keys;
use function array_values;
use function basename;
use function glob;
use function in_array;
use function is_array;
use function is_scalar;
use function preg_match;
use function preg_replace;
use function sort;
use function sprintf;

/**
 * Guards against untranslated strings: in every non-en catalogue (all domains) a key must exist, and a value identical to
 * the English one (any length, ignoring values without letters once placeholders are removed) must be listed in
 * ALLOWED_IDENTICAL for that locale. An allow-list entry that is no longer identical to English is reported as stale.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const DIR = __DIR__ . '/../../src/Resources/translations';

    /**
     * Keys that are correctly identical to English in a locale (brands, product and technical names, codes, loanwords
     * and cognates), per locale.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_IDENTICAL = [
        'de' => [
            'board.title',
            'colour.orange',
            'consent.purpose.newsletter',
            'consent.source.api',
            'consent.source.import',
            'deal.status_label',
            'deals.title',
            'field.contact_name',
            'field.form.position',
            'field.name',
            'field.region',
            'field.tags',
            'field.website',
            'field_type.text',
            'field_type.url',
            'funnel.form.name',
            'funnel.kind.deal',
            'import.form.format',
            'import.form.subject',
            'import.format.csv',
            'import.format.vcard',
            'import.show_title',
            'import.title',
            'list.filter.name',
            'list.filter.tag',
            'nav.deals',
            'nav.label',
            'nav.tags',
            'record.details',
            'record.vcard',
            'role.partner',
            'search.group.deals',
            'stage.form.name',
            'stats.csv.count',
            'tag.edit_title',
            'tag.form.name',
            'tags.form.tags',
            'tags.title',
            'tags.title_record',
            'type.deal',
        ],
        'es' => [
            'consent.source.api',
            'field.roles',
            'import.format.csv',
            'import.format.vcard',
            'nav.label',
            'purpose.general',
            'record.vcard',
            'value.no',
        ],
        'fr' => [
            'colour.orange',
            'consent.source.api',
            'consent.source.import',
            'deal.form.contact',
            'deal.history.via',
            'field.description',
            'field.email',
            'field.form.position',
            'field.form.type',
            'field.mobile',
            'field_type.date',
            'field_type.email',
            'import.form.format',
            'import.format.csv',
            'import.format.vcard',
            'import.subject.contacts',
            'link.form.type',
            'list.title.contact',
            'nav.contacts',
            'nav.label',
            'note.form.body',
            'note.title',
            'record.contacts',
            'record.vcard',
            'search.group.contacts',
            'type.contact',
        ],
        'it' => [
            'consent.purpose.newsletter',
            'consent.source.api',
            'field.email',
            'field_type.email',
            'field_type.url',
            'import.form.file',
            'import.format.csv',
            'import.format.vcard',
            'import.step.file',
            'list.filter.tag',
            'nav.label',
            'record.vcard',
            'role.partner',
            'tag.edit_title',
            'value.no',
        ],
        'nl' => [
            'activity.host',
            'activity.via_token',
            'consent.source.api',
            'consent.source.import',
            'deal.form.contact',
            'deal.history.via',
            'deal.status.open',
            'deal.status_label',
            'deals.title',
            'field.email',
            'field.form.label',
            'field.form.type',
            'field.tags',
            'field.website',
            'field_type.email',
            'field_type.url',
            'funnel.kind.deal',
            'import.format.csv',
            'import.format.vcard',
            'import.show_title',
            'link.form.label',
            'link.form.object',
            'link.form.type',
            'list.filter.tag',
            'lost_reason.timing',
            'nav.deals',
            'nav.label',
            'nav.tags',
            'next_action.status.open',
            'record.details',
            'record.vcard',
            'role.partner',
            'search.group.deals',
            'stage.kind.open',
            'stats.csv.count',
            'tag.edit_title',
            'tags.form.tags',
            'tags.title',
            'tags.title_record',
            'type.contact',
            'type.deal',
        ],
        'pt' => [
            'consent.source.api',
            'deal.history.via',
            'field.email',
            'field_type.email',
            'import.format.csv',
            'import.format.vcard',
            'nav.label',
            'record.vcard',
        ],
    ];

    /** @return iterable<string, array{string, string}> */
    public static function catalogues(): iterable
    {
        $files = glob(self::DIR . '/*.yaml') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (preg_match('/^(.+)\.([a-z]{2})\.yaml$/', basename($file), $m) === 1 && $m[2] !== 'en') {
                yield basename($file) => [$m[1], $m[2]];
            }
        }
    }

    #[DataProvider('catalogues')]
    public function testLocaleHasEveryEnglishKey(string $domain, string $locale): void
    {
        $missing = array_values(array_diff(array_keys($this->load($domain, 'en')), array_keys($this->load($domain, $locale))));

        self::assertSame([], $missing, sprintf('Keys of %s.en.yaml missing in %s.%s.yaml', $domain, $domain, $locale));
    }

    #[DataProvider('catalogues')]
    public function testNoUntranslatedEnglishValues(string $domain, string $locale): void
    {
        $english = $this->load($domain, 'en');
        $target  = $this->load($domain, $locale);
        $allowed = self::ALLOWED_IDENTICAL[$locale] ?? [];
        $gaps    = [];

        foreach ($english as $key => $value) {
            if (!isset($target[$key]) || $target[$key] !== $value || in_array($key, $allowed, true)) {
                continue;
            }

            if (preg_match('/[^\W\d_]/u', (string) preg_replace('/%[^%\s]+%|\{[^}]+\}/', '', $value)) === 1) {
                $gaps[] = $key;
            }
        }

        self::assertSame([], $gaps, sprintf('Values identical to English in %s.%s.yaml (translate or allow-list)', $domain, $locale));
    }

    public function testAllowListHasNoStaleEntries(): void
    {
        $english = [];
        $locales = [];

        foreach (self::catalogues() as [$domain, $locale]) {
            $english += $this->load($domain, 'en');
            $locales[$locale] = ($locales[$locale] ?? []) + $this->load($domain, $locale);
        }

        $stale = [];

        foreach (self::ALLOWED_IDENTICAL as $locale => $keys) {
            foreach ($keys as $key) {
                if (!isset($english[$key]) || ($locales[$locale][$key] ?? null) !== $english[$key]) {
                    $stale[] = $locale . ':' . $key;
                }
            }
        }

        self::assertSame([], $stale, 'Allow-list entries that no longer exist or are no longer identical to English');
    }

    /** @return array<string, string> */
    private function load(string $domain, string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/%s.%s.yaml', self::DIR, $domain, $locale));

        return $this->flatten(is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $out;
    }
}
