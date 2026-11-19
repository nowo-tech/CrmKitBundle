<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Database\Api;

use Nowo\CrmKitBundle\Api\CrmApiLevel;
use Nowo\CrmKitBundle\Api\CrmApiPrincipal;
use Nowo\CrmKitBundle\Domain\Value\RecordId;

use function gc_collect_cycles;
use function is_array;
use function memory_get_usage;
use function str_starts_with;

/**
 * Worker mode (REQ-RUNTIME-002, kernel reset false; design §14): one kept kernel serves 150 calls alternating a session
 * page in tenant acme, a REST principal in tenant beta and an in-process MCP principal without amounts in acme. No data
 * crosses, no amount leaks to the view principal, no bundle entity stays managed, and memory stays flat.
 */
final class ApiWorkerTest extends ApiTestCase
{
    public function testOneKeptKernelServesEveryKindOfCaller(): void
    {
        $this->bootApi();
        $this->ctx()->grant('beta', 'ana', 'view', 'edit', 'manage', 'amounts_view');
        $acme                = $this->company('Acme Tools');
        $this->ctx()->tenant = 'beta';
        $beta                = $this->company('Beta Corp');
        $this->ctx()->tenant = 'acme';
        $rest                = $this->principal(CrmApiLevel::Edit, token: 'rest');
        $mcp                 = $this->principal(CrmApiLevel::View, amounts: false, channel: CrmApiPrincipal::CHANNEL_MCP, token: 'mcp');
        $base                = 0;
        for ($i = 0; $i < 150; ++$i) {
            if ($i === 30) {
                gc_collect_cycles();
                $base = memory_get_usage();
            }
            if ($i % 3 === 0) {
                $this->ctx()->tenant = 'acme';
                $this->client->request('GET', '/crm/companies');
                self::assertSame(200, $this->httpCode());
                self::assertStringNotContainsString('Beta Corp', (string) $this->client->getResponse()->getContent());
            } elseif ($i % 3 === 1) {
                $this->ctx()->tenant = 'beta';
                $this->as($rest);
                $list  = $this->api('GET', '/companies');
                $items = is_array($list['items'] ?? null) ? $list['items'] : [];
                self::assertCount(1, $items);
                self::assertSame($beta, is_array($items[0] ?? null) ? ($items[0]['id'] ?? null) : null);
                $this->api('GET', '/companies/' . $acme);
                self::assertSame(404, $this->httpCode(), 'acme is not reached from beta');
            } else {
                $this->ctx()->tenant = 'acme';
                $record              = $this->reads()->company($mcp, $this->acme(), new RecordId($acme));
                self::assertSame([null, true], [$record->fact('amounts'), $record->fact('amounts_hidden')]);
            }
            foreach ($this->em()->getUnitOfWork()->getIdentityMap() as $class => $entities) {
                self::assertFalse(str_starts_with($class, 'Nowo\\CrmKitBundle\\') && $entities !== [], $class . ' stays managed');
            }
        }
        gc_collect_cycles();
        self::assertLessThan(8 * 1024 * 1024, memory_get_usage() - $base);
    }
}
