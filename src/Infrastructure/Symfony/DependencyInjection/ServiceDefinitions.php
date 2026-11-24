<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection;

use Nowo\CrmKitBundle\Api\Operation\CrmApiCursorCodec;
use Nowo\CrmKitBundle\Api\Operation\CrmApiDealOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiGuard;
use Nowo\CrmKitBundle\Api\Operation\CrmApiIdempotency;
use Nowo\CrmKitBundle\Api\Operation\CrmApiNextActionOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiPipeline;
use Nowo\CrmKitBundle\Api\Operation\CrmApiReadOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiRecordOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiRecords;
use Nowo\CrmKitBundle\Api\Operation\CrmApiRelationOperations;
use Nowo\CrmKitBundle\Api\Operation\CrmApiWriter;
use Nowo\CrmKitBundle\Api\Port\CrmApiCallerFactoryInterface;
use Nowo\CrmKitBundle\Api\Port\CrmApiIdempotencyStoreInterface;
use Nowo\CrmKitBundle\Application\ActorPersonalData;
use Nowo\CrmKitBundle\Application\ConsentService;
use Nowo\CrmKitBundle\Application\CrmAccess;
use Nowo\CrmKitBundle\Application\CrmBatchLookup;
use Nowo\CrmKitBundle\Application\CrmExporter;
use Nowo\CrmKitBundle\Application\CrmPersonalData;
use Nowo\CrmKitBundle\Application\CrmReader;
use Nowo\CrmKitBundle\Application\FieldService;
use Nowo\CrmKitBundle\Application\HostLinkService;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotConnectionService;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotImportService;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotRecordWriter;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotRunner;
use Nowo\CrmKitBundle\Application\HubSpot\HubSpotTokenOpener;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotReaderInterface;
use Nowo\CrmKitBundle\Application\HubSpot\Port\HubSpotStoreInterface;
use Nowo\CrmKitBundle\Application\ImportService;
use Nowo\CrmKitBundle\Application\MergeService;
use Nowo\CrmKitBundle\Application\NoteService;
use Nowo\CrmKitBundle\Application\PartyCatalogue;
use Nowo\CrmKitBundle\Application\Pipeline\BuiltInCrmTaskPort;
use Nowo\CrmKitBundle\Application\Pipeline\DealReader;
use Nowo\CrmKitBundle\Application\Pipeline\DealService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelReader;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelService;
use Nowo\CrmKitBundle\Application\Pipeline\FunnelStatistics;
use Nowo\CrmKitBundle\Application\Pipeline\NextActionService;
use Nowo\CrmKitBundle\Application\Pipeline\NextActionViews;
use Nowo\CrmKitBundle\Application\Pipeline\PartyStageService;
use Nowo\CrmKitBundle\Application\Pipeline\PipelineHousekeeping;
use Nowo\CrmKitBundle\Application\Pipeline\PipelineSupport;
use Nowo\CrmKitBundle\Application\Pipeline\Port\PipelineStoreInterface;
use Nowo\CrmKitBundle\Application\PlanCounter;
use Nowo\CrmKitBundle\Application\Port\CrmActorDataStoreInterface;
use Nowo\CrmKitBundle\Application\Port\CrmBatchLookupStoreInterface;
use Nowo\CrmKitBundle\Application\Port\CrmStoreInterface;
use Nowo\CrmKitBundle\Application\Port\ExternalRefHooksInterface;
use Nowo\CrmKitBundle\Application\Port\ImportRunDispatcherInterface;
use Nowo\CrmKitBundle\Application\RecordPurger;
use Nowo\CrmKitBundle\Application\RecordService;
use Nowo\CrmKitBundle\Application\RecordViews;
use Nowo\CrmKitBundle\Application\Roles;
use Nowo\CrmKitBundle\Application\SettingsService;
use Nowo\CrmKitBundle\Application\Shared\TransactionRunnerInterface;
use Nowo\CrmKitBundle\Application\Taggings;
use Nowo\CrmKitBundle\Application\TagService;
use Nowo\CrmKitBundle\Application\WriteSupport;
use Nowo\CrmKitBundle\Bridge\SearchKit\CrmDealSearchDefinition;
use Nowo\CrmKitBundle\Bridge\SearchKit\CrmRecordSearchDefinition;
use Nowo\CrmKitBundle\Domain\Enum\SubjectType;
use Nowo\CrmKitBundle\Domain\Port\CrmAccessCheckerInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmActorEmailLookupInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmFeatureGateInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostObjectResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPanelProviderInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPlanLimitsInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmRecordVisibilityInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmTaskPort;
use Nowo\CrmKitBundle\Domain\Port\CrmTenantResolverInterface;
use Nowo\CrmKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\CrmKitBundle\Domain\Service\ConsentServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmActorPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmBatchLookupInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmCatalogueInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmExporterInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmPersonalDataInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmReaderInterface;
use Nowo\CrmKitBundle\Domain\Service\CrmSettingsInterface;
use Nowo\CrmKitBundle\Domain\Service\FieldServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HostLinkServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HostObjectPickerInterface;
use Nowo\CrmKitBundle\Domain\Service\HubSpotConnectionServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\HubSpotImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\ImportServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\MergeServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\NoteServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\RecordServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TagServiceInterface;
use Nowo\CrmKitBundle\Domain\Service\TimelineInterface;
use Nowo\CrmKitBundle\Infrastructure\Crypto\SodiumCrmSecretBox;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\Api\DoctrineCrmApiIdempotencyStore;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\CrmMetadataListener;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineCrmActorDataStore;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineCrmBatchLookupStore;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineCrmStore;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineHubSpotStore;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrinePipelineStore;
use Nowo\CrmKitBundle\Infrastructure\Doctrine\DoctrineTransactionRunner;
use Nowo\CrmKitBundle\Infrastructure\HubSpot\HttpHubSpotReader;
use Nowo\CrmKitBundle\Infrastructure\HubSpot\HubSpotHttpClientFactory;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\ConfiguredCrmApiTenantResolver;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\Controller\CrmApiController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiAuthenticatorInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiExceptionListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiRouteLoader;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiTenantResolverInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\PrincipalCallerFactory;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\SecurityUserCrmApiAuthenticator;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Command\ApiPurgeIdempotencyCommand;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Command\CountersRebuildCommand;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Command\ImportResumeCommand;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Command\PurgeCommand;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Command\SecretsRotateCommand;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\AdminController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\HubSpotImportController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\ImportController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineAdminController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineDialogs;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineForms;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\PipelineRecordBlock;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordForms;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordPage;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\RecordPartsController;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\UiOptions;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Controller\UiSupport;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\AdminFilterType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\AffiliationType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ConsentType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\DealFilterType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\DealType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\FieldDefinitionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\FilterType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\FunnelType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\HostLinkType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\HubSpotConnectType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\HubSpotMappingType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\HubSpotStartType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ImportConfirmType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\ImportUploadType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\LifecycleType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\MergePickType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\MergeType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\NextActionType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\NoteType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\RecordType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\SettingsType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\StageMoveType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\StageType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\StatsFilterType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\TagsAssignType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Form\TagType;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\CrmConflictFormatter;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\DomainExceptionListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\EntityManagerHygieneListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger\MessengerImportRunDispatcher;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger\RunCrmImport;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Messenger\RunCrmImportHandler;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\ConfiguredFeatureGate;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\ConfiguredPlanLimits;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\PsrLogAuditSink;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SecurityActorDirectory;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SingleTenantResolver;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Port\SymfonyUuidGenerator;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Routing\UiRouteLoader;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\AllowAllCrmKitAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\ConfigurableCrmKitAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmActorResolverInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitAccessCheckerInterface;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitAccessListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmKitGuard;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\CrmResourceAccessChecker;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\IdentifierActorResolver;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\ScopedRecordVisibility;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Security\SecurityCrmAccessChecker;
use Nowo\CrmKitBundle\NowoCrmKitBundle;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponseFormatterInterface;
use Nowo\UiKitBundle\Dialog\RemoteDialogResponder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function class_exists;
use function intdiv;
use function interface_exists;
use function is_array;
use function is_string;
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
    /** SearchKit's entity search definition (a literal: SearchKit is optional and only `Bridge` imports it). */
    public const SEARCH_DEFINITION_CLASS = 'Nowo\\SearchKitBundle\\Provider\\Entity\\EntitySearchDefinition';

    /** SearchKit's `EntitySearchDefinition::TAG` (a literal: SearchKit is optional). */
    public const SEARCH_DEFINITION_TAG = 'nowo_search_kit.entity_definition';

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
        // 0.2.1: the actor id of a signed-in person (security.actor_resolver, default getUserIdentifier()).
        $s->set(IdentifierActorResolver::class);
        $resolver = is_array($config['security'] ?? null) && is_string($config['security']['actor_resolver'] ?? null) ? $config['security']['actor_resolver'] : IdentifierActorResolver::class;
        $s->alias(CrmActorResolverInterface::class, $resolver)->public();
        $s->set($p . 'default.access_checker', SecurityCrmAccessChecker::class)
            ->args([service(CrmKitAccessCheckerInterface::class), service(CrmResourceAccessChecker::class), service('security.token_storage')->nullOnInvalid(), param($p . 'security.allow_unauthenticated'), service(CrmActorResolverInterface::class)]);
        $s->set(CrmKitGuard::class)->args([service(CrmAccess::class)])->public();
        $s->set(CrmKitAccessListener::class)->args([service(CrmKitGuard::class), service(CrmAccessCheckerInterface::class), service('security.firewall.map')->nullOnInvalid()])
            ->tag('kernel.event_listener', ['event' => 'kernel.controller', 'method' => 'onKernelController', 'priority' => 16]);
        $s->set($p . 'default.feature_gate', ConfiguredFeatureGate::class)->args([param($p . 'feature.default_enabled')]);
        $s->set($p . 'default.plan_limits', ConfiguredPlanLimits::class)->args([['companies' => param($p . 'limits.companies'), 'contacts' => param($p . 'limits.contacts'), 'deals' => param($p . 'limits.deals')]]);
        $s->set($p . 'default.actor_directory', SecurityActorDirectory::class)->args([service('security.token_storage')->nullOnInvalid(), service(CrmActorResolverInterface::class)]);
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
        // The sealed keyset cursors of the API and of the deal list (0.3.0, audit VS-3).
        $s->set(CrmApiCursorCodec::class)->args([param('kernel.secret')]);
        $s->set(CrmAccess::class)->args([service(CrmTenantResolverInterface::class), service(CrmFeatureGateInterface::class), service(CrmAccessCheckerInterface::class), $clock, service(CrmRecordVisibilityInterface::class)])->public();
        $s->set(WriteSupport::class)->args([service(CrmAccess::class), service(CrmStoreInterface::class), service(TransactionRunnerInterface::class), service(IdGeneratorInterface::class), service(CrmAuditSinkInterface::class), $events]);
        $s->set(PlanCounter::class)->args([service(CrmStoreInterface::class), service(CrmPlanLimitsInterface::class)])->public();
        $s->set(Taggings::class)->args([service(WriteSupport::class)]);
        $s->set(PartyCatalogue::class)->args([param($p . 'roles.catalogue'), param($p . 'affiliations.purposes')]);
        $s->alias(CrmCatalogueInterface::class, PartyCatalogue::class)->public();
        $s->set(Roles::class)->args([service(WriteSupport::class), service(PartyCatalogue::class)]);
        $s->set(RecordViews::class)->args([service(CrmStoreInterface::class), service(CrmActorDirectoryInterface::class), service(PartyCatalogue::class), service(CrmActorResolverInterface::class)]);
        $pipeline     = is_array($config['pipeline'] ?? null) && ($config['pipeline']['enabled'] ?? false) === true;
        $housekeeping = $pipeline ? service(PipelineHousekeeping::class) : null;
        $hubspot      = self::hubspotOn($config);
        $messenger    = self::messengerOn($config);
        // 0.10.0: merges, purges and erasures keep the external references in step (only while HubSpot is on).
        $refs = $hubspot ? service(ExternalRefHooksInterface::class) : null;
        $s->set(RecordPurger::class)->args([service(CrmStoreInterface::class), service(TransactionRunnerInterface::class), service(CrmAuditSinkInterface::class), $clock, $events, $housekeeping, $refs])->public();
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
        $s->alias(HostObjectPickerInterface::class, HostLinkService::class)->public();
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
            $hubspot ? service(HubSpotRunner::class) : null,
            param($p . 'import.hubspot.pages_per_request'),
            $messenger ? service(ImportRunDispatcherInterface::class) : null,
        ])->public();
        $s->alias(ImportServiceInterface::class, ImportService::class)->public();
        $s->set(MergeService::class)->args([service(WriteSupport::class), service(RecordViews::class), service(PlanCounter::class), $housekeeping, $refs]);
        $s->alias(MergeServiceInterface::class, MergeService::class)->public();
        $s->set(CrmExporter::class)->args([service(WriteSupport::class), service(CrmReader::class), service(RecordViews::class)]);
        $s->alias(CrmExporterInterface::class, CrmExporter::class)->public();
        // System API (0.2.1): a host person's references across tenants.
        $s->set(DoctrineCrmActorDataStore::class)->args([service(DoctrineTransactionRunner::class)]);
        $s->alias(CrmActorDataStoreInterface::class, DoctrineCrmActorDataStore::class);
        $s->set(ActorPersonalData::class)->args([service(CrmActorDataStoreInterface::class), service(TransactionRunnerInterface::class), service(CrmAuditSinkInterface::class), $clock, $events]);
        $s->alias(CrmActorPersonalDataInterface::class, ActorPersonalData::class)->public();
        $s->set(CrmPersonalData::class)->args([service(WriteSupport::class), service(PlanCounter::class), $housekeeping, $refs]);
        $s->alias(CrmPersonalDataInterface::class, CrmPersonalData::class)->public();
        $s->set(SettingsService::class)->args([service(WriteSupport::class)]);
        $s->alias(CrmSettingsInterface::class, SettingsService::class)->public();
        // System API for the host's backend jobs: tenant-scoped, no access checks (USAGE "Batch lookups for host jobs").
        $s->set(CrmBatchLookup::class)->args([service(CrmBatchLookupStoreInterface::class), service(PartyCatalogue::class)]);
        $s->alias(CrmBatchLookupInterface::class, CrmBatchLookup::class)->public();

        // Console.
        $api = is_array($config['api'] ?? null) && ($config['api']['enabled'] ?? false) === true;
        $s->set(PurgeCommand::class)->args([service(RecordPurger::class), $api ? service(CrmApiIdempotencyStoreInterface::class) : null, $clock])->tag('console.command');
        $s->set(ImportResumeCommand::class)->args([service(ImportService::class)])->tag('console.command');
        $s->set(CountersRebuildCommand::class)->args([service(CrmStoreInterface::class), service(PlanCounter::class), service(TransactionRunnerInterface::class), $housekeeping])->tag('console.command');

        // HTTP.
        $s->set(CrmApiRouteLoader::class)->args([param($p . 'api.http.enabled'), param($p . 'api_path'), param($p . 'pipeline.enabled')])->tag('routing.loader');
        $s->set(UiRouteLoader::class)->args([param($p . 'ui.enabled'), param($p . 'ui.path'), param($p . 'pipeline.enabled'), param($p . 'import.hubspot.enabled')])->tag('routing.loader');
        $s->set(DomainExceptionListener::class)->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 32]);
        if (interface_exists(ConflictResponseFormatterInterface::class)) {
            $s->set(CrmConflictFormatter::class)->tag('nowo_transaction_doctrine.conflict_response_formatter', ['priority' => 10]);
        }
        $ui = is_array($config['ui'] ?? null) && ($config['ui']['enabled'] ?? false) === true;
        if ($pipeline) {
            self::pipeline($container);
        }
        if ($hubspot) {
            self::hubspot($container, $config);
        }
        if ($messenger) {
            $s->set(ImportRunDispatcherInterface::class, MessengerImportRunDispatcher::class)->args([service('messenger.default_bus'), $clock]);
            $s->set(RunCrmImportHandler::class)->args([service(ImportService::class), service(ImportRunDispatcherInterface::class), param($p . 'import.messenger.units')])
                ->tag('messenger.message_handler', ['handles' => RunCrmImport::class]);
        }
        if ($api) {
            self::api($container, $pipeline);
        }
        if ($ui) {
            self::ui($container, self::tabIcons($config['ui']));
        }
        if ($ui && $pipeline) {
            self::pipelineUi($container);
        }
        if ($ui && $hubspot) {
            $s->set(HubSpotImportController::class)->args([service(UiSupport::class), service(HubSpotConnectionServiceInterface::class), service(HubSpotImportServiceInterface::class), service(ImportServiceInterface::class), param($p . 'import.sync_chunks')])->public()->tag('controller.service_arguments');
            foreach ([HubSpotConnectType::class, HubSpotStartType::class, HubSpotMappingType::class] as $type) {
                $s->set($type)->tag('form.type');
            }
        }
        $search = is_array($config['search'] ?? null) && ($config['search']['enabled'] ?? false) === true;
        if ($ui && $search && class_exists(self::SEARCH_DEFINITION_CLASS)) {
            self::search($container, $pipeline);
        }
    }

    /**
     * The SearchKit groups (0.3.0, SearchKit >= 1.9 installed, `ui.enabled` and `search.enabled`): `crm_companies`,
     * `crm_contacts` and, with `pipeline.enabled`, `crm_deals`, tagged for SearchKit's entity provider pass. Switch a
     * group off or reorder it with `nowo_search_kit.groups.<key>`.
     */
    private static function search(ContainerConfigurator $container, bool $pipeline): void
    {
        $s    = $container->services();
        $p    = self::P;
        $args = [service(CrmAccess::class), service(DoctrineTransactionRunner::class), service('router'), service('translator')->nullOnInvalid()];
        foreach ([SubjectType::Company, SubjectType::Contact] as $i => $type) {
            $s->set($p . 'search.' . $type->value, CrmRecordSearchDefinition::class)->args([$type, ...$args])
                ->tag(self::SEARCH_DEFINITION_TAG, ['priority' => 60 - $i])->public();
        }
        if ($pipeline) {
            $s->set($p . 'search.deal', CrmDealSearchDefinition::class)->args($args)->tag(self::SEARCH_DEFINITION_TAG, ['priority' => 58])->public();
        }
    }

    /**
     * Whether `import.hubspot.enabled` (0.10.0).
     *
     * @param array<string, mixed> $config
     */
    public static function hubspotOn(array $config): bool
    {
        $import = is_array($config['import'] ?? null) ? $config['import'] : [];

        return is_array($import['hubspot'] ?? null) && ($import['hubspot']['enabled'] ?? false) === true;
    }

    /**
     * Whether `import.messenger.enabled` (0.10.0).
     *
     * @param array<string, mixed> $config
     */
    public static function messengerOn(array $config): bool
    {
        $import = is_array($config['import'] ?? null) ? $config['import'] : [];

        return is_array($import['messenger'] ?? null) && ($import['messenger']['enabled'] ?? false) === true;
    }

    /**
     * The HubSpot import (0.10.0, only with `import.hubspot.enabled`): the kit's own HttpClient, the reader, the store,
     * the default secret box (the secret box alias and the OutboundUrlGuard pin are set by CrmPortsPass), the services
     * and the rotation command.
     *
     * @param array<string, mixed> $config
     */
    private static function hubspot(ContainerConfigurator $container, array $config): void
    {
        $s       = $container->services();
        $p       = self::P;
        $clock   = service($p . 'clock');
        $secrets = is_array($config['secrets'] ?? null) ? $config['secrets'] : [];
        $s->set($p . 'hubspot.http_client', HttpClientInterface::class)->factory([HubSpotHttpClientFactory::class, 'create'])->args([param($p . 'import.hubspot.http.timeout'), param($p . 'import.hubspot.http.max_duration')]);
        $s->set(HttpHubSpotReader::class)->args([service($p . 'hubspot.http_client'), param($p . 'import.hubspot.http.max_response_bytes'), null]);
        $s->alias(HubSpotReaderInterface::class, HttpHubSpotReader::class);
        $s->set(DoctrineHubSpotStore::class)->args([service(DoctrineTransactionRunner::class)]);
        $s->alias(HubSpotStoreInterface::class, DoctrineHubSpotStore::class);
        $s->alias(ExternalRefHooksInterface::class, DoctrineHubSpotStore::class);
        if (is_string($secrets['current_key'] ?? null) && is_array($secrets['keys'] ?? null)) {
            $s->set($p . 'default.secret_box', SodiumCrmSecretBox::class)->args([$secrets['keys'], $secrets['current_key']]);
        }
        $s->set(HubSpotTokenOpener::class)->args([service(CrmSecretBoxInterface::class), service(HubSpotStoreInterface::class), service(TransactionRunnerInterface::class)]);
        $s->set(HubSpotRecordWriter::class)->args([service(WriteSupport::class), service(HubSpotStoreInterface::class), service(PlanCounter::class), param($p . 'security.resource_scoping')]);
        $s->set(HubSpotRunner::class)->args([
            service(WriteSupport::class),
            service(HubSpotStoreInterface::class),
            service(HubSpotReaderInterface::class),
            service(HubSpotTokenOpener::class),
            service(HubSpotRecordWriter::class),
            $clock,
            service(CrmActorDirectoryInterface::class),
            service(CrmActorEmailLookupInterface::class)->nullOnInvalid(),
            service('logger')->nullOnInvalid(),
            param($p . 'import.hubspot.page_size'),
            param($p . 'import.hubspot.max_records'),
            param($p . 'import.hubspot.rate_limit.max_attempts'),
            param($p . 'import.hubspot.rate_limit.max_retry_after'),
        ]);
        $s->set(HubSpotConnectionService::class)->args([service(WriteSupport::class), service(HubSpotStoreInterface::class), service(HubSpotReaderInterface::class), service(CrmSecretBoxInterface::class), param($p . 'import.hubspot.connect_rate_limit.limit'), param($p . 'import.hubspot.connect_rate_limit.interval'), service('logger')->nullOnInvalid()]);
        $s->alias(HubSpotConnectionServiceInterface::class, HubSpotConnectionService::class)->public();
        $s->set(HubSpotImportService::class)->args([service(WriteSupport::class), service(HubSpotStoreInterface::class), service(HubSpotReaderInterface::class), service(HubSpotTokenOpener::class), param($p . 'import.messenger.enabled'), param($p . 'import.hubspot.browse_rate_limit.limit'), param($p . 'import.hubspot.browse_rate_limit.interval'), service('logger')->nullOnInvalid()]);
        $s->alias(HubSpotImportServiceInterface::class, HubSpotImportService::class)->public();
        $s->set(SecretsRotateCommand::class)->args([service(HubSpotStoreInterface::class), service(CrmSecretBoxInterface::class), service(TransactionRunnerInterface::class), $clock, service('logger')->nullOnInvalid()])->tag('console.command');
    }

    /**
     * The funnels (0.3.0, only with `pipeline.enabled`).
     */
    private static function pipeline(ContainerConfigurator $container): void
    {
        $s = $container->services();
        $p = self::P;
        $s->set(DoctrinePipelineStore::class)->args([service(DoctrineTransactionRunner::class)]);
        $s->alias(PipelineStoreInterface::class, DoctrinePipelineStore::class);
        $s->set(PipelineSupport::class)->args([service(WriteSupport::class), service(PipelineStoreInterface::class), param($p . 'pipeline.lost_reasons')]);
        $s->set(PipelineHousekeeping::class)->args([service(PipelineStoreInterface::class), service(CrmStoreInterface::class), service(PlanCounter::class)]);
        // The task port: pipeline.task_port, else the TaskBoard bridge (CrmPortsPass), else the built-in list.
        $s->set($p . 'default.task_port', BuiltInCrmTaskPort::class);
        $s->alias(CrmTaskPort::class, $p . 'default.task_port')->public();
        $s->set(NextActionViews::class)->args([service(CrmTaskPort::class)]);
        $s->set(FunnelReader::class)->args([service(PipelineSupport::class)])->public();
        $s->set(FunnelService::class)->args([service(PipelineSupport::class)])->public();
        $s->set(DealService::class)->args([service(PipelineSupport::class), service(PlanCounter::class), service(CrmActorDirectoryInterface::class), service(PipelineHousekeeping::class)])->public();
        $s->set(DealReader::class)->args([service(PipelineSupport::class), service(RecordViews::class), service(NextActionViews::class)])->public();
        $s->set(FunnelStatistics::class)->args([service(PipelineSupport::class), service(DealReader::class)])->public();
        $s->set(PartyStageService::class)->args([service(PipelineSupport::class)])->public();
        $s->set(NextActionService::class)->args([service(PipelineSupport::class), service(CrmTaskPort::class), service(CrmActorDirectoryInterface::class), service(NextActionViews::class)])->public();
    }

    /**
     * The API (0.3.0, only with `api.enabled`): the operations, the principal caller, the idempotency store and, with
     * `api.http.enabled`, the `/crm/v1` routes' controller and error listener.
     */
    private static function api(ContainerConfigurator $container, bool $pipeline): void
    {
        $s = $container->services();
        $p = self::P;
        $s->set(DoctrineCrmApiIdempotencyStore::class)->args([service(DoctrineTransactionRunner::class)]);
        $s->alias(CrmApiIdempotencyStoreInterface::class, DoctrineCrmApiIdempotencyStore::class)->public();
        $s->set(PrincipalCallerFactory::class)->args([service(CrmKitAccessCheckerInterface::class), service(CrmResourceAccessChecker::class), service(ScopedRecordVisibility::class), service(CrmActorDirectoryInterface::class), service(CrmActorResolverInterface::class)]);
        $s->alias(CrmApiCallerFactoryInterface::class, PrincipalCallerFactory::class)->public();
        $s->set(CrmApiOperations::class)->args([$pipeline])->public();
        $s->set(CrmApiGuard::class)->args([service(CrmAccess::class), service(CrmApiCallerFactoryInterface::class), service(CrmApiOperations::class)]);
        $s->set(CrmApiIdempotency::class)->args([service(CrmApiIdempotencyStoreInterface::class), service($p . 'clock'), param($p . 'api.idempotency_ttl_seconds')]);
        $s->set(CrmApiWriter::class)->args([service(CrmApiGuard::class), service(CrmApiIdempotency::class)]);
        $s->set(CrmApiRecords::class)->args([service(WriteSupport::class)]);
        $pipe = null;
        if ($pipeline) {
            $s->set(CrmApiPipeline::class)->args([service(FunnelReader::class), service(DealReader::class), service(DealService::class), service(FunnelStatistics::class), service(NextActionService::class), service(PartyStageService::class)]);
            $pipe = service(CrmApiPipeline::class);
        }
        $s->set(CrmApiReadOperations::class)->args([service(CrmApiGuard::class), service(CrmApiRecords::class), service(WriteSupport::class), service(CrmReader::class), service(RecordViews::class), service(CrmApiCursorCodec::class), service(CrmApiOperations::class), param($p . 'gdpr.purposes'), $pipe])->public();
        $s->set(CrmApiRelationOperations::class)->args([service(CrmApiGuard::class), service(CrmApiWriter::class), service(CrmApiRecords::class), service(RecordService::class), service(NoteService::class), service(TagService::class), service(RecordViews::class), service(WriteSupport::class)])->public();
        $s->set(CrmApiRecordOperations::class)->args([service(CrmApiGuard::class), service(CrmApiWriter::class), service(CrmApiRecords::class), service(CrmApiReadOperations::class), service(RecordService::class), service(CrmReader::class), service(WriteSupport::class)])->public();
        $s->set(CrmApiDealOperations::class)->args([service(CrmApiGuard::class), service(CrmApiWriter::class), service(CrmApiCursorCodec::class), $pipe])->public();
        $s->set(CrmApiNextActionOperations::class)->args([service(CrmApiGuard::class), service(CrmApiWriter::class), service(CrmApiRecords::class), $pipe])->public();
        $s->set(ApiPurgeIdempotencyCommand::class)->args([service(CrmApiIdempotencyStoreInterface::class), service($p . 'clock')])->tag('console.command');

        // HTTP (api.http.enabled): the routes come from the loader; the controller and the error listener are cheap.
        $s->set(SecurityUserCrmApiAuthenticator::class)->args([service('security.token_storage')->nullOnInvalid(), ['level' => param($p . 'api.http.default_grant.level'), 'amounts' => param($p . 'api.http.default_grant.amounts'), 'consents' => param($p . 'api.http.default_grant.consents'), 'destructive' => param($p . 'api.http.default_grant.destructive')], service(CrmActorResolverInterface::class)]);
        $s->set(ConfiguredCrmApiTenantResolver::class)->args([service(CrmTenantResolverInterface::class)]);
        $s->alias(CrmApiAuthenticatorInterface::class, $p . 'api.authenticator');
        $s->alias(CrmApiTenantResolverInterface::class, $p . 'api.tenant_resolver');
        $s->set(CrmApiController::class)->args([service(CrmApiAuthenticatorInterface::class), service(CrmApiTenantResolverInterface::class), service(CrmApiReadOperations::class), service(CrmApiRecordOperations::class), service(CrmApiRelationOperations::class), service(CrmApiDealOperations::class), service(CrmApiNextActionOperations::class), param($p . 'api.page_size')])->public()->tag('controller.service_arguments');
        $s->set(CrmApiExceptionListener::class)->args([service('nowo_transaction_doctrine.conflict_response_policy'), service('logger')->nullOnInvalid()])
            ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 128]);
    }

    /**
     * The funnel pages (0.3.0, with `ui.enabled` and `pipeline.enabled`).
     */
    private static function pipelineUi(ContainerConfigurator $container): void
    {
        $s = $container->services();
        $p = self::P;
        $s->set(PipelineForms::class)->args([service(RecordForms::class), service(CrmReaderInterface::class), param($p . 'pipeline.lost_reasons')]);
        $s->set(PipelineController::class)->args([service(UiSupport::class), service(FunnelReader::class), service(DealReader::class), service(DealService::class), service(NextActionService::class), service(PartyStageService::class), service(PipelineForms::class), service(CrmAccess::class), service(CrmApiCursorCodec::class), service(PipelineDialogs::class), service(CrmReaderInterface::class)])->public()->tag('controller.service_arguments');
        $s->set(PipelineAdminController::class)->args([service(UiSupport::class), service(FunnelReader::class), service(FunnelService::class), service(FunnelStatistics::class), service(PipelineForms::class), service(CrmAccess::class), service(PipelineDialogs::class)])->public()->tag('controller.service_arguments');
        $s->set(PipelineDialogs::class)->args([service(UiSupport::class), service(RemoteDialogResponder::class)->nullOnInvalid()]);
        $s->set(PipelineRecordBlock::class)->args([service(UiSupport::class), service(FunnelReader::class), service(DealReader::class), service(PartyStageService::class), service(NextActionService::class), service(PipelineForms::class), service(PipelineDialogs::class)]);
        foreach ([DealFilterType::class, DealType::class, FunnelType::class, StageType::class, StageMoveType::class, NextActionType::class, StatsFilterType::class, LifecycleType::class] as $type) {
            $s->set($type)->tag('form.type');
        }
    }

    /**
     * The host overrides of the area tab icons (`ui.tab_icons`, validated by the tree), passed as they are: the
     * built-in set fills the areas the host leaves out ({@see UiOptions::tabIcon()}).
     *
     * @return array<string, string|null>
     */
    private static function tabIcons(mixed $ui): array
    {
        $icons = is_array($ui) && is_array($ui['tab_icons'] ?? null) ? $ui['tab_icons'] : [];
        $out   = [];
        foreach ($icons as $area => $icon) {
            $out[(string) $area] = is_string($icon) ? $icon : null;
        }

        return $out;
    }

    /**
     * The pages (only with `ui.enabled`).
     *
     * @param array<string, string|null> $tabIcons host overrides of the area tab icons
     */
    private static function ui(ContainerConfigurator $container, array $tabIcons): void
    {
        $s = $container->services();
        $p = self::P;
        $s->set(UiOptions::class)->args([param($p . 'ui.row_actions'), param($p . 'ui.header_actions'), param($p . 'ui.record_actions'), param($p . 'ui.record_tabs'), param($p . 'ui.filters_fold'), param($p . 'ui.filters_slot'), param($p . 'ui.list_tabs'), param($p . 'ui.admin_create'), param($p . 'ui.settings_width'), $tabIcons, param($p . 'ui.list_per_page'), param($p . 'ui.record_create'), param($p . 'ui.pipeline_forms')]);
        $s->set(UiSupport::class)->args([service('twig'), service('router'), service('form.factory'), service(CrmKitGuard::class), service('translator'), service('security.csrf.token_manager'), param($p . 'ui.layout'), param($p . 'ui.page_size'), param($p . 'pipeline.enabled'), service(UiOptions::class)]);
        $s->set(RecordForms::class)->args([service(UiSupport::class), service(CrmReaderInterface::class), tagged_iterator(CrmHostObjectResolverInterface::HOST_OBJECT_TAG), param($p . 'gdpr.purposes'), service(PartyCatalogue::class)]);
        $s->set(RecordPage::class)->args([service(UiSupport::class), service(CrmReaderInterface::class), service(CrmAccess::class), service(RecordForms::class), service(PipelineRecordBlock::class)->nullOnInvalid()]);
        $s->set(RecordController::class)->args([service(UiSupport::class), service(CrmReaderInterface::class), service(RecordServiceInterface::class), service(MergeServiceInterface::class), service(CrmExporterInterface::class), service(RecordForms::class), service(RecordPage::class), service(ImportServiceInterface::class), service(RemoteDialogResponder::class)->nullOnInvalid()])->public()->tag('controller.service_arguments');
        $s->set(RecordPartsController::class)->args([service(UiSupport::class), service(NoteServiceInterface::class), service(TagServiceInterface::class), service(HostLinkServiceInterface::class), service(RecordServiceInterface::class), service(ConsentServiceInterface::class), service(CrmExporterInterface::class), service(CrmPersonalDataInterface::class), service(RecordForms::class), service(HostObjectPickerInterface::class)])->public()->tag('controller.service_arguments');
        $s->set(ImportController::class)->args([service(UiSupport::class), service(ImportServiceInterface::class), service(RecordForms::class), param($p . 'import.sync_chunks'), param($p . 'import.max_bytes'), param($p . 'import.hubspot.enabled')])->public()->tag('controller.service_arguments');
        foreach ([ActionType::class, AdminFilterType::class, AffiliationType::class, ConsentType::class, FieldDefinitionType::class, FilterType::class, HostLinkType::class, ImportConfirmType::class, ImportUploadType::class, MergePickType::class, MergeType::class, NoteType::class, RecordType::class, SettingsType::class, TagType::class, TagsAssignType::class] as $type) {
            $s->set($type)->tag('form.type');
        }
        $s->set(AdminController::class)->args([service(UiSupport::class), service(CrmReaderInterface::class), service(TagServiceInterface::class), service(FieldServiceInterface::class), service(CrmSettingsInterface::class), service(CrmAccess::class), service(RemoteDialogResponder::class)->nullOnInvalid()])->public()->tag('controller.service_arguments');
    }

    /**
     * Chunks of the confirm request: `sync_rows` / `chunk_rows`, at least 1.
     */
    public static function syncChunks(int $syncRows, int $chunkRows): int
    {
        return max(1, intdiv($syncRows, max(1, $chunkRows)));
    }
}
