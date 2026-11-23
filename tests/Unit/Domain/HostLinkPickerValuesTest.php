<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Domain;

use InvalidArgumentException;
use Nowo\CrmKitBundle\Domain\Value\HostObjectMatch;
use Nowo\CrmKitBundle\Domain\Value\HostObjectRef;
use Nowo\CrmKitBundle\Domain\Value\HostTypeLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * The link picker's values (0.7.0): the type label and the `<type>:<id>` key, taken byte for byte (REQ-SEC-006).
 */
final class HostLinkPickerValuesTest extends TestCase
{
    public function testTypeLabels(): void
    {
        $trans = HostTypeLabel::trans('crm.host.project');
        self::assertSame(['crm.host.project', 'messages'], [$trans->text, $trans->domain]);
        self::assertSame('admin+intl-icu', HostTypeLabel::trans('k', 'admin+intl-icu')->domain);
        $text = HostTypeLabel::text('Project');
        self::assertSame(['Project', null], [$text->text, $text->domain]);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function badLabels(): iterable
    {
        yield 'blank' => [' ', null];
        yield 'too long' => [str_repeat('a', 201), null];
        yield 'bad domain' => ['key', 'a b'];
        yield 'empty domain' => ['key', ''];
    }

    #[DataProvider('badLabels')]
    public function testBadTypeLabels(string $text, ?string $domain): void
    {
        $this->expectException(InvalidArgumentException::class);
        $domain === null ? HostTypeLabel::text($text) : HostTypeLabel::trans($text, $domain);
    }

    public function testKeysRoundTripByteForByte(): void
    {
        foreach (['project:p1', 'issue:a:b', 'ticket:T-1', 'doc.page:ÁRbol'] as $key) {
            self::assertSame($key, HostObjectRef::fromKey($key)?->key());
        }
        self::assertSame('a:b', HostObjectRef::fromKey('issue:a:b')?->id);
        foreach (['', 'project', ':p1', 'project:', 'Project:p1', 'project: p1', "project:p\u{200B}1"] as $bad) {
            self::assertNull(HostObjectRef::fromKey($bad), $bad);
        }
        $match = new HostObjectMatch(new HostObjectRef('project', 'p1'), 'Website');
        self::assertSame(['project:p1', 'Website'], [$match->ref->key(), $match->label]);
    }
}
