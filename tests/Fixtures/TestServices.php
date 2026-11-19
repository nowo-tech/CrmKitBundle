<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Fixtures;

use Nowo\CrmKitBundle\Domain\Event\ConsentRecorded;
use Nowo\CrmKitBundle\Domain\Event\ContactErased;
use Nowo\CrmKitBundle\Domain\Event\HostLinkAdded;
use Nowo\CrmKitBundle\Domain\Event\HostLinkRemoved;
use Nowo\CrmKitBundle\Domain\Event\ImportCompleted;
use Nowo\CrmKitBundle\Domain\Event\NoteAdded;
use Nowo\CrmKitBundle\Domain\Event\RecordCreated;
use Nowo\CrmKitBundle\Domain\Event\RecordPurged;
use Nowo\CrmKitBundle\Domain\Event\RecordRestored;
use Nowo\CrmKitBundle\Domain\Event\RecordsMerged;
use Nowo\CrmKitBundle\Domain\Event\RecordsPurged;
use Nowo\CrmKitBundle\Domain\Event\RecordTrashed;
use Nowo\CrmKitBundle\Domain\Event\RecordUpdated;
use Nowo\CrmKitBundle\Domain\Event\RolesChanged;
use Nowo\CrmKitBundle\Domain\Event\TagsChanged;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * The test host's services: ports, recorder, clock, a panel, a host resolver and the race injector.
 */
final class TestServices
{
    public const USERS = ['ana' => ['password' => 'x', 'roles' => ['ROLE_USER']], 'root' => ['password' => 'x', 'roles' => ['ROLE_ADMIN']], 'leo' => ['password' => 'x', 'roles' => ['ROLE_USER']]];

    public const EVENTS = [RecordCreated::class, RecordUpdated::class, RecordTrashed::class, RecordRestored::class, RecordPurged::class, RecordsMerged::class, ContactErased::class, NoteAdded::class, ConsentRecorded::class, ImportCompleted::class, RecordsPurged::class, TagsChanged::class, RolesChanged::class, HostLinkAdded::class, HostLinkRemoved::class];

    /** The bundle configuration that points every port at the test services. */
    public const PORTS = [
        'tenant_resolver' => 'test.ports',
        'access_checker'  => 'test.ports',
        'feature_gate'    => 'test.ports',
        'plan_limits'     => 'test.ports',
        'actor_directory' => 'test.ports',
        'audit_sink'      => 'test.recorder',
        'clock'           => 'test.clock',
    ];

    public static function register(ContainerConfigurator $container): void
    {
        $s = $container->services();
        $s->set('test.context', TestContext::class)->public();
        $s->set('test.ports', TestPorts::class)->args([service('test.context')])->public();
        $recorder = $s->set('test.recorder', TestRecorder::class)->public();
        foreach (self::EVENTS as $event) {
            $recorder->tag('kernel.event_listener', ['event' => $event, 'method' => 'onEvent']);
        }
        $s->set('test.queries', QueryLog::class)->public();
        $s->set('test.query_counter', QueryCounter::class)->args([service('test.queries')])->tag('doctrine.middleware');
        $s->set('test.clock', MockClock::class)->args(['2026-11-18 10:00:00', 'UTC'])->public();
        $s->set('test.panel', TestPanel::class)->args([service('test.context')])->tag(CrmPanelProviderInterface::PANEL_TAG);
        $s->set('test.projects', TestProjects::class)->tag(CrmHostObjectResolverInterface::HOST_OBJECT_TAG);
        $s->set('test.race', RaceInjector::class)->args([service('test.context')])->tag('doctrine.event_listener', ['event' => 'prePersist'])->tag('doctrine.event_listener', ['event' => 'preUpdate'])->tag('doctrine.event_listener', ['event' => 'preRemove']);
    }
}
