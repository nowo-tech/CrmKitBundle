<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection;

use Nowo\CrmKitBundle\Application\ConsentService;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\CrmBatchLookup;
use Nowo\CrmKitBundle\Application\CrmExporter;
use Nowo\CrmKitBundle\Application\CrmPersonalData;
use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Application\FieldService;
use Nowo\CrmKitBundle\Application\HostLinkService;
use Nowo\CrmKitBundle\Application\ImportService;
use Nowo\CrmKitBundle\Application\MergeService;
use Nowo\CrmKitBundle\Application\NoteService;
use Nowo\CrmKitBundle\Application\PartyCatalogue;
use Nowo\CrmKitBundle\Application\PlanCounter;
use Nowo\CrmKitBundle\Application\Port\CrmBatchLookupStoreInterface;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Application\RecordPurger;
use Nowo\CrmKitBundle\Application\RecordService;
use Nowo\CrmKitBundle\Application\RecordViews;
use Nowo\CrmKitBundle\Application\Roles;
use Nowo\CrmKitBundle\Application\SettingsService;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Application\Taggings;
use Nowo\CrmKitBundle\Application\TagService;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmFeatureGateInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPlanLimitsInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmRecordVisibilityInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmTenantResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmBatchLookupInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmCatalogueInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmExporterInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmSettingsInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TimelineInterface;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\CrmMetadataListener;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineCrmBatchLookupStore;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineCrmStore;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Command\CountersRebuildCommand;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Command\ImportResumeCommand;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Command\PurgeCommand;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\AdminController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\ImportController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordForms;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordPage;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordPartsController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\UiSupport;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\AffiliationType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ConsentType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\FieldDefinitionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\FilterType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\HostLinkType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ImportConfirmType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ImportUploadType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\MergePickType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\MergeType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\NoteType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\RecordType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\SettingsType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\TagsAssignType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\TagType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\CrmConflictFormatter;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\DomainExceptionListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\EntityManagerHygieneListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\ConfiguredFeatureGate;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\ConfiguredPlanLimits;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\PsrLogAuditSink;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SecurityActorDirectory;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SingleTenantResolver;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SymfonyUuidGenerator;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\AllowAllCrmKitAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\ConfigurableCrmKitAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitAccessCheckerInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitAccessListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitGuard;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmResourceAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\ScopedRecordVisibility;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\SecurityCrmAccessChecker;
use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function intdiv;
use function interface_exists;
use function is_array;
use function max;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * The bundle's services (explicit arguments, no autowiring of bundle classes). Port defaults are registered under
 * `nowo_crm_kit.default.*`; {@see Compiler\CrmPortsPass} points each port at the host's service when configured and
 * wires the optional infrastructure (transaction-doctrine, SearchKit, the clock). Every service is stateless (worker
 * mode).
 *
 * @internal
 */
final class ServiceDefinitions
{
    /** Prefix of the bundle's service ids and parameters. */
    public const P = NowoCrmKitBundle::ALIAS . '.';

    /**
     * Registers the services.
     *
     * @param array<string, mixed> $config the validated configuration
     */
    public static function register(ContainerConfigurator $container, array $config): void
    {
        $s      = $container->services();
        $p      = self::P;
        $logger = service('logger')->nullOnInvalid();
        $events = service('event_dispatcher')->nullOnInvalid();
        $clock  = service($p . 'clock');

        // Port defaults.
        $s->set($p . 'default.tenant_resolver', SingleTenantResolver::class);
        // REQ-UI-002: the area checker (security.*_roles, or the host's security.access_checker), the record checker
        // with its event, and the port adapter the application asks.
        $auth = service('security.authorization_checker')->nullOnInvalid();
        $s->set(ConfigurableCrmKitAccessChecker::class)->args([$auth, param($p . 'security.access_roles'), param($p . 'security.manage_roles'), param($p . 'security.settings_roles'), param($p . 'security.export_roles'), param($p . 'security.erase_roles'), param($p . 'security.amounts_roles'), param($p . 'security.campaigns_roles')]);
        $s->set(AllowAllCrmKitAccessChecker::class);
        $s->alias(CrmKitAccessCheckerInterface::class, ConfigurableCrmKitAccessChecker::class)->public();
        $s->set(ScopedRecordVisibility::class)->args([service(CrmKitAccessCheckerInterface::class), service('security.token_storage')->nullOnInvalid(), $events, param($p . 'security.resource_scoping')]);
        $s->alias(CrmRecordVisibilityInterface::class, ScopedRecordVisibility::class);
        $s->set(CrmResourceAccessChecker::class)->args([$events, service(CrmRecordVisibilityInterface::class)]);
        $s->set($p . 'default.access_checker', SecurityCrmAccessChecker::class)
            ->args([service(CrmKitAccessCheckerInterface::class), service(CrmResourceAccessChecker::class), service('security.token_storage')->nullOnInvalid(), param($p . 'security.allow_unauthenticated')]);
        $s->set(CrmKitGuard::class)->args([service(CrmAccess::class)])->public();
        $s->set(CrmKitAccessListener::class)->args([service(CrmKitGuard::class), service(CrmAccessCheckerInterface::class), service('security.firewall.map')->nullOnInvalid()])
            ->tag('kernel.event_listener', ['event' => 'kernel.controller', 'method' => 'onKernelController', 'priority' => 16]);
        $s->set($p . 'default.feature_gate', ConfiguredFeatureGate::class)->args([param($p . 'feature.default_enabled')]);
        $s->set($p . 'default.plan_limits', ConfiguredPlanLimits::class)->args([['companies' => param($p . 'limits.companies'), 'contacts' => param($p . 'limits.contacts')]]);
        $s->set($p . 'default.actor_directory', SecurityActorDirectory::class)->args([service('security.token_storage')->nullOnInvalid()]);
        $s->set($p . 'default.audit_sink', PsrLogAuditSink::class)->args([$logger]);
        $s->set(IdGeneratorInterface::class, SymfonyUuidGenerator::class);
        foreach ([
            CrmTenantResolverInterface::class => 'tenant_resolver',
            CrmAccessCheckerInterface::class  => 'access_checker',
            CrmFeatureGateInterface::class    => 'feature_gate',
            CrmPlanLimitsInterface::class     => 'plan_limits',
            CrmActorDirectoryInterface::class => 'actor_directory',
            CrmAuditSinkInterface::class      => 'audit_sink',
        ] as $interface => $port) {
            $s->alias($interface, $p . 'default.' . $port);
        }

        // Persistence.
        $s->set(DoctrineTransactionRunner::class)->args([service('doctrine'), service($p . 'write_unit'), param($p . 'doctrine.entity_manager')])->public();
        $s->alias(TransactionRunnerInterface::class, DoctrineTransactionRunner::class);
        $s->set(DoctrineCrmStore::class)->args([service(DoctrineTransactionRunner::class)]);
        $s->alias(CrmStoreInterface::class, DoctrineCrmStore::class);
        $s->set(DoctrineCrmBatchLookupStore::class)->args([service(DoctrineTransactionRunner::class)]);
        $s->alias(CrmBatchLookupStoreInterface::class, DoctrineCrmBatchLookupStore::class);
        $s->set(CrmMetadataListener::class)->args([param($p . 'doctrine.table_prefix')])->tag('doctrine.event_listener', ['event' => 'loadClassMetadata']);
        $s->set(EntityManagerHygieneListener::class)->args([service(DoctrineTransactionRunner::class)])
            ->tag('kernel.event_listener', ['event' => 'kernel.finish_request', 'method' => 'clear', 'priority' => -2048])
            ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'clear', 'priority' => -2048])
            ->tag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'clear', 'priority' => -2048]);

        // Application.
        $s->set(CrmAccess::class)->args([service(CrmTenantResolverInterface::class), service(CrmFeatureGateInterface::class), service(CrmAccessCheckerInterface::class), $clock, service(CrmRecordVisibilityInterface::class)])->public();
        $s->set(WriteSupport::class)->args([service(CrmAccess::class), service(CrmStoreInterface::class), service(TransactionRunnerInterface::class), service(IdGeneratorInterface::class), service(CrmAuditSinkInterface::class), $events]);
        $s->set(PlanCounter::class)->args([service(CrmStoreInterface::class), service(CrmPlanLimitsInterface::class)])->public();
        $s->set(Taggings::class)->args([service(WriteSupport::class)]);
        $s->set(PartyCatalogue::class)->args([param($p . 'roles.catalogue'), param($p . 'affiliations.purposes')]);
        $s->alias(CrmCatalogueInterface::class, PartyCatalogue::class)->public();
        $s->set(Roles::class)->args([service(WriteSupport::class), service(PartyCatalogue::class)]);
        $s->set(RecordViews::class)->args([service(CrmStoreInterface::class), service(CrmActorDirectoryInterface::class), service(PartyCatalogue::class)]);
        $s->set(RecordPurger::class)->args([service(CrmStoreInterface::class), service(TransactionRunnerInterface::class), service(CrmAuditSinkInterface::class), $clock, $events])->public();
        $s->set(RecordService::class)->args([service(WriteSupport::class), service(PlanCounter::class), service(Taggings::class), service(CrmActorDirectoryInterface::class), service(RecordPurger::class), service(Roles::class), service(PartyCatalogue::class)]);
        $s->alias(RecordServiceInterface::class, RecordService::class)->public();
        $s->set(CrmReader::class)->args([service(WriteSupport::class), service(RecordViews::class), service(CrmActorDirectoryInterface::class), tagged_iterator(CrmPanelProviderInterface::PANEL_TAG), tagged_iterator(CrmHostObjectResolverInterface::HOST_OBJECT_TAG), $logger])->public();
        $s->alias(CrmReaderInterface::class, CrmReader::class)->public();
        $s->set(TagService::class)->args([service(WriteSupport::class), service(Taggings::class)]);
        $s->alias(TagServiceInterface::class, TagService::class)->public();
        $s->set(FieldService::class)->args([service(WriteSupport::class), param($p . 'fields.max_per_subject')]);
        $s->alias(FieldServiceInterface::class, FieldService::class)->public();
        $s->set(NoteService::class)->args([service(WriteSupport::class)]);
        $s->alias(NoteServiceInterface::class, NoteService::class)->public();
        $s->set(HostLinkService::class)->args([service(WriteSupport::class), tagged_iterator(CrmHostObjectResolverInterface::HOST_OBJECT_TAG)]);
        $s->alias(HostLinkServiceInterface::class, HostLinkService::class)->public();
        $s->alias(TimelineInterface::class, HostLinkService::class)->public();
        $s->set(ConsentService::class)->args([service(WriteSupport::class), param($p . 'gdpr.purposes')]);
        $s->alias(ConsentServiceInterface::class, ConsentService::class)->public();
        $s->set(ImportService::class)->args([
            service(WriteSupport::class),
            service(PlanCounter::class),
            $clock,
            service(PartyCatalogue::class),
            param($p . 'import.max_rows'),
            param($p . 'import.max_bytes'),
            param($p . 'import.chunk_rows'),
            param($p . 'import.stale_claim_seconds'),
        ])->public();
        $s->alias(ImportServiceInterface::class, ImportService::class)->public();
        $s->set(MergeService::class)->args([service(WriteSupport::class), service(RecordViews::class), service(PlanCounter::class)]);
        $s->alias(MergeServiceInterface::class, MergeService::class)->public();
        $s->set(CrmExporter::class)->args([service(WriteSupport::class), service(CrmReader::class), service(RecordViews::class)]);
        $s->alias(CrmExporterInterface::class, CrmExporter::class)->public();
        $s->set(CrmPersonalData::class)->args([service(WriteSupport::class), service(PlanCounter::class)]);
        $s->alias(CrmPersonalDataInterface::class, CrmPersonalData::class)->public();
        $s->set(SettingsService::class)->args([service(WriteSupport::class)]);
        $s->alias(CrmSettingsInterface::class, SettingsService::class)->public();
        // System API for the host's backend jobs: tenant-scoped, no access checks (USAGE "Batch lookups for host jobs").
        $s->set(CrmBatchLookup::class)->args([service(CrmBatchLookupStoreInterface::class), service(PartyCatalogue::class)]);
        $s->alias(CrmBatchLookupInterface::class, CrmBatchLookup::class)->public();

        // Console.
        $s->set(PurgeCommand::class)->args([service(RecordPurger::class)])->tag('console.command');
        $s->set(ImportResumeCommand::class)->args([service(ImportService::class)])->tag('console.command');
        $s->set(CountersRebuildCommand::class)->args([service(CrmStoreInterface::class), service(PlanCounter::class), service(TransactionRunnerInterface::class)])->tag('console.command');

        // HTTP.
        $s->set(UiRouteLoader::class)->args([param($p . 'ui.enabled'), param($p . 'ui.path')])->tag('routing.loader');
        $s->set(DomainExceptionListener::class)->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 32]);
        if (interface_exists(ConflictResponseFormatterInterface::class)) {
            $s->set(CrmConflictFormatter::class)->tag('nowo_transaction_doctrine.conflict_response_formatter', ['priority' => 10]);
        }
        $ui = is_array($config['ui'] ?? null) && ($config['ui']['enabled'] ?? false) === true;
        if ($ui) {
            self::ui($container);
        }
    }

    /**
     * The pages (only with `ui.enabled`).
     */
    private static function ui(ContainerConfigurator $container): void
    {
        $s = $container->services();
        $p = self::P;
        $s->set(UiSupport::class)->args([service('twig'), service('router'), service('form.factory'), service(CrmKitGuard::class), service('translator'), service('security.csrf.token_manager'), param($p . 'ui.layout'), param($p . 'ui.page_size')]);
        $s->set(RecordForms::class)->args([service(UiSupport::class), service(CrmReaderInterface::class), tagged_iterator(CrmHostObjectResolverInterface::HOST_OBJECT_TAG), param($p . 'gdpr.purposes'), service(PartyCatalogue::class)]);
        $s->set(RecordPage::class)->args([service(UiSupport::class), service(CrmReaderInterface::class), service(CrmAccess::class), service(RecordForms::class)]);
        $s->set(RecordController::class)->args([service(UiSupport::class), service(CrmReaderInterface::class), service(RecordServiceInterface::class), service(MergeServiceInterface::class), service(CrmExporterInterface::class), service(RecordForms::class), service(RecordPage::class)])->public()->tag('controller.service_arguments');
        $s->set(RecordPartsController::class)->args([service(UiSupport::class), service(NoteServiceInterface::class), service(TagServiceInterface::class), service(HostLinkServiceInterface::class), service(RecordServiceInterface::class), service(ConsentServiceInterface::class), service(CrmExporterInterface::class), service(CrmPersonalDataInterface::class), service(RecordForms::class)])->public()->tag('controller.service_arguments');
        $s->set(ImportController::class)->args([service(UiSupport::class), service(ImportServiceInterface::class), service(RecordForms::class), param($p . 'import.sync_chunks'), param($p . 'import.max_bytes')])->public()->tag('controller.service_arguments');
        foreach ([ActionType::class, AffiliationType::class, ConsentType::class, FieldDefinitionType::class, FilterType::class, HostLinkType::class, ImportConfirmType::class, ImportUploadType::class, MergePickType::class, MergeType::class, NoteType::class, RecordType::class, SettingsType::class, TagType::class, TagsAssignType::class] as $type) {
            $s->set($type)->tag('form.type');
        }
        $s->set(AdminController::class)->args([service(UiSupport::class), service(CrmReaderInterface::class), service(TagServiceInterface::class), service(FieldServiceInterface::class), service(CrmSettingsInterface::class), service(CrmAccess::class)])->public()->tag('controller.service_arguments');
    }

    /**
     * Chunks of the confirm request: `sync_rows` / `chunk_rows`, at least 1.
     */
    public static function syncChunks(int $syncRows, int $chunkRows): int
    {
        return max(1, intdiv($syncRows, max(1, $chunkRows)));
    }
}
