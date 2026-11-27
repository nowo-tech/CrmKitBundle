<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use LogicException;
use Nowo\CrmKitBundle\Bridge\DoctrineEncrypt\DoctrineEncryptCrmSecretBox;
use Nowo\CrmKitBundle\Bridge\NotifyKit\NotifyKitCrmNotifier;
use Nowo\CrmKitBundle\Bridge\OutboundUrlGuard\GuardedHubSpotRequestOptions;
use Nowo\CrmKitBundle\Bridge\TaskBoard\CrmTaskBoardUserResolverInterface;
use Nowo\CrmKitBundle\Bridge\TaskBoard\TaskBoardCrmTaskPort;
use Nowo\CrmKitBundle\Domain\Port\CrmActorDirectoryInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmActorEmailLookupInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmAuditSinkInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmFeatureGateInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmHostActivitySourceInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmNotifierInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmPlanLimitsInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmRecordAudienceInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmSecretBoxInterface;
use Nowo\CrmKitBundle\Domain\Port\CrmTaskPort;
use Nowo\CrmKitBundle\Domain\Port\CrmTenantResolverInterface;
use Nowo\CrmKitBundle\Infrastructure\HubSpot\HttpHubSpotReader;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\ConfiguredCrmApiTenantResolver;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\SecurityUserCrmApiAuthenticator;
use Nowo\CrmKitBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Event\CrmKitEvents;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Http\ConflictFallbackListener;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistry;
use Nowo\DoctrineEncryptBundle\Encryptors\EncryptorRegistryInterface;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\OutboundUrlGuardBundle\Guard\OutboundUrlGuard;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiReadOperations;
use Nowo\TaskBoardBundle\Api\Operation\TaskApiTaskOperations;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_values;
use function class_exists;
use function in_array;
use function interface_exists;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Points the port aliases at the host's services and wires the optional infrastructure (contract sections 8 and 10):
 * a configured service id replaces each default; transaction-doctrine's write unit (its bundle is required: the
 * container build fails with the fix without it) and the 409 fallback listener when its conflict responder is turned
 * off; the clock; the task port of the next actions. (The SearchKit groups are registered by the extension, 0.3.0.)
 *
 * @internal
 */
final class CrmPortsPass implements CompilerPassInterface
{
    /**
     * Wires the ports.
     */
    public function process(ContainerBuilder $container): void
    {
        $p = ServiceDefinitions::P;
        foreach ([
            'tenant_resolver' => CrmTenantResolverInterface::class,
            'feature_gate'    => CrmFeatureGateInterface::class,
            'plan_limits'     => CrmPlanLimitsInterface::class,
            'actor_directory' => CrmActorDirectoryInterface::class,
            'audit_sink'      => CrmAuditSinkInterface::class,
        ] as $port => $interface) {
            $id = $container->getParameter($p . $port);
            if (is_string($id) && $id !== '') {
                $container->setAlias($interface, $id);
            }
        }
        // 0.3.0: the JSON API's authenticator and tenant resolver (the host's services, else the defaults).
        if ($container->hasParameter($p . 'api.http.authenticator')) {
            $authenticator = $container->getParameter($p . 'api.http.authenticator');
            $tenants       = $container->getParameter($p . 'api.http.tenant_resolver');
            $container->setAlias($p . 'api.authenticator', is_string($authenticator) && $authenticator !== '' ? $authenticator : SecurityUserCrmApiAuthenticator::class);
            $container->setAlias($p . 'api.tenant_resolver', is_string($tenants) && $tenants !== '' ? $tenants : ConfiguredCrmApiTenantResolver::class);
        }
        $clock = $container->getParameter($p . 'clock');
        // FrameworkBundle (required) always defines `clock`.
        $container->setAlias($p . 'clock', is_string($clock) && $clock !== '' ? $clock : 'clock');
        // REQ-RUNTIME-003: the commit ledger, its DBAL middleware and the request watermark (CONFLICT_002 after a
        // commit earlier in the same request) come only with NowoTransactionDoctrineBundle, so it is required.
        if (!$container->has('nowo_transaction_doctrine.write_unit')) {
            throw new LogicException('nowo_crm_kit needs Nowo\\TransactionDoctrine\\Symfony\\NowoTransactionDoctrineBundle (nowo-tech/transaction-doctrine, already installed): add it to config/bundles.php.');
        }
        $container->setAlias($p . 'write_unit', 'nowo_transaction_doctrine.write_unit');
        if (!$container->has('nowo_transaction_doctrine.conflict_responder')) {
            // The host turned the bundle's conflict responder off: the CRM's routes keep their 409 answers.
            $container->setDefinition(ConflictFallbackListener::class, (new Definition(ConflictFallbackListener::class))
                ->setArguments([new Definition(ConflictResponsePolicy::class)])
                ->addTag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onKernelException', 'priority' => 64]));
        }
        if ($container->getParameter($p . 'pipeline.enabled') === true) {
            $this->taskPort($container);
        }
        $lookup = $container->getParameter($p . 'actor_email_lookup');
        if (is_string($lookup) && $lookup !== '') {
            $container->setAlias(CrmActorEmailLookupInterface::class, $lookup);
        }
        if ($container->getParameter($p . 'import.hubspot.enabled') === true) {
            $this->hubspot($container);
        }
        if ($container->hasParameter($p . 'activities.enabled') && $container->getParameter($p . 'activities.enabled') === true) {
            $this->activities($container);
        }
        if ($container->getParameter($p . 'import.messenger.enabled') === true && !$container->has('messenger.default_bus')) {
            throw new LogicException('nowo_crm_kit.import.messenger.enabled needs symfony/messenger with a default bus (framework.messenger).');
        }
    }

    /**
     * The logged activities' ports (0.11.0): `activities.host_source`, `activities.audience` and `activities.notifier`
     * replace the defaults; without a notifier, the NotifyKit bridge when NotifyKit's notifier is defined.
     */
    private function activities(ContainerBuilder $container): void
    {
        $p = ServiceDefinitions::P;
        foreach (['host_source' => CrmHostActivitySourceInterface::class, 'audience' => CrmRecordAudienceInterface::class, 'notifier' => CrmNotifierInterface::class] as $key => $interface) {
            $id = $container->getParameter($p . 'activities.' . $key);
            if (is_string($id) && $id !== '') {
                $container->setAlias($interface, $id);
            }
        }
        $audience = $container->getParameter($p . 'activities.audience');
        if (!is_string($audience) || $audience === '') {
            foreach ($container->findTaggedServiceIds('kernel.event_listener') as $id => $tags) {
                foreach ($tags as $tag) {
                    if (is_array($tag) && ($tag['event'] ?? null) === CrmKitEvents::RECORD_ACCESS_CHECK) {
                        // Audit ZCR-L5: the default audience cannot ask the listeners about other people.
                        $container->log($this, sprintf('nowo_crm_kit: "%s" listens to %s but activities.audience is not set: @mention candidates and recipients follow security.resource_scoping only. Implement CrmRecordAudienceInterface with the same rule.', $id, CrmKitEvents::RECORD_ACCESS_CHECK));

                        break 2;
                    }
                }
            }
        }
        $notifier = $container->getParameter($p . 'activities.notifier');
        if ((!is_string($notifier) || $notifier === '') && interface_exists(NotifierInterface::class) && $container->has(NotifierInterface::class)) {
            $container->setDefinition(NotifyKitCrmNotifier::class, (new Definition(NotifyKitCrmNotifier::class))->setArguments([new Reference(NotifierInterface::class), new Reference('router'), $container->getParameter($p . 'activities.notify_kit.recipient_type')]));
            $container->setAlias(CrmNotifierInterface::class, NotifyKitCrmNotifier::class);
        }
    }

    /**
     * The HubSpot import (0.10.0): the secret box is `secrets.box` (the
     * DoctrineEncrypt bridge is defined here when chosen and DoctrineEncrypt is installed, with the profiles of
     * `secrets.doctrine_encrypt`, 0.10.1) or the sodium default; the OutboundUrlGuard pin follows
     * `import.hubspot.outbound_url_guard`.
     */
    private function hubspot(ContainerBuilder $container): void
    {
        $p   = ServiceDefinitions::P;
        $box = $container->getParameter($p . 'secrets.box');
        if ($box === DoctrineEncryptCrmSecretBox::class && !$container->has($box)) {
            if (!interface_exists(EncryptorRegistryInterface::class) || !$container->has(EncryptorRegistryInterface::class)) {
                throw new LogicException('nowo_crm_kit.secrets.box is the DoctrineEncrypt bridge but nowo-tech/doctrine-encrypt-bundle is not installed and enabled.');
            }
            $profile  = $container->getParameter($p . 'secrets.doctrine_encrypt.profile');
            $profile  = is_string($profile) && $profile !== '' ? $profile : null;
            $previous = [];
            foreach ((array) $container->getParameter($p . 'secrets.doctrine_encrypt.previous') as $keyId => $old) {
                $previous[(string) $keyId] = is_string($old) && $old !== '' ? $old : null;
            }
            // Audit ZAM-D4: a profile that is not Halite or Defuse fails the build, not the first seal.
            foreach ([$profile, ...array_values($previous)] as $name) {
                $this->assertAuthenticated($container, $name);
            }
            $container->setDefinition($box, (new Definition($box))->setArguments([new Reference(EncryptorRegistryInterface::class), $profile, false, $container->getParameter($p . 'secrets.doctrine_encrypt.key_id'), $previous]));
        }
        $container->setAlias(CrmSecretBoxInterface::class, is_string($box) && $box !== '' ? $box : $p . 'default.secret_box');
        $guard = $container->getParameter($p . 'import.hubspot.outbound_url_guard');
        $has   = class_exists(OutboundUrlGuard::class) && $container->has(OutboundUrlGuard::class);
        if ($guard === 'required' && !$has) {
            throw new LogicException('nowo_crm_kit.import.hubspot.outbound_url_guard is "required" but nowo-tech/outbound-url-guard-bundle is not installed and enabled.');
        }
        if ($guard !== 'off' && $has) {
            $container->setDefinition(GuardedHubSpotRequestOptions::class, (new Definition(GuardedHubSpotRequestOptions::class))->setArguments([new Reference(OutboundUrlGuard::class)]));
            $container->getDefinition(HttpHubSpotReader::class)->replaceArgument(2, new Reference(GuardedHubSpotRequestOptions::class));
        }
    }

    /**
     * Refuses a DoctrineEncrypt profile whose encryptor is not Halite or Defuse, or that does not exist, when the
     * registry is DoctrineEncrypt's own (0.10.1, audit ZAM-D4). A registry the host replaced is checked at the first
     * seal or unseal instead (the box refuses it there too).
     */
    private function assertAuthenticated(ContainerBuilder $container, ?string $profile): void
    {
        $registry = $container->findDefinition(EncryptorRegistryInterface::class);
        $map      = $registry->getArguments()[0] ?? null;
        if ($container->getParameterBag()->resolveValue($registry->getClass()) !== EncryptorRegistry::class || !is_array($map)) {
            return;
        }
        $name      = $profile ?? 'default';
        $encryptor = $map[$name] ?? null;
        if (!$encryptor instanceof Reference) {
            throw new LogicException('nowo_crm_kit.secrets.doctrine_encrypt: "' . $name . '" is not a DoctrineEncrypt profile (nowo_doctrine_encrypt.profiles).');
        }
        $class = $container->getParameterBag()->resolveValue($container->findDefinition((string) $encryptor)->getClass());
        if (!is_string($class) || !in_array($class, DoctrineEncryptCrmSecretBox::AUTHENTICATED, true)) {
            throw new LogicException('nowo_crm_kit.secrets.doctrine_encrypt: the DoctrineEncrypt profile "' . $name . '" must use the Halite or Defuse encryptor, not ' . (is_string($class) ? $class : 'an unknown class') . ' (define your own DoctrineEncryptCrmSecretBox service with allowCustom: true for another authenticated encryptor).');
        }
    }

    /**
     * The task port of the next actions (0.3.0): `pipeline.task_port`, else the TaskBoard bridge when
     * `pipeline.taskboard.board_id` is set (TaskBoard installed and a {@see CrmTaskBoardUserResolverInterface} service
     * registered: the build fails with the fix otherwise), else the built-in list.
     */
    private function taskPort(ContainerBuilder $container): void
    {
        $p    = ServiceDefinitions::P;
        $port = $container->getParameter($p . 'pipeline.task_port');
        if (is_string($port) && $port !== '') {
            $container->setAlias(CrmTaskPort::class, $port)->setPublic(true);

            return;
        }
        $board = $container->getParameter($p . 'pipeline.taskboard.board_id');
        if (!is_string($board) || $board === '') {
            return;
        }
        if (!class_exists(TaskApiTaskOperations::class) || !$container->has(TaskApiTaskOperations::class) || !$container->has(TaskApiReadOperations::class)) {
            throw new LogicException('nowo_crm_kit.pipeline.taskboard.board_id needs nowo-tech/task-board-bundle (its API operations): install and enable it, or leave board_id empty.');
        }
        if (!$container->has(CrmTaskBoardUserResolverInterface::class)) {
            throw new LogicException('nowo_crm_kit.pipeline.taskboard needs a service ' . CrmTaskBoardUserResolverInterface::class . ' (the TaskBoard user of a CRM actor).');
        }
        $column = $container->getParameter($p . 'pipeline.taskboard.column');
        $container->setDefinition(TaskBoardCrmTaskPort::class, (new Definition(TaskBoardCrmTaskPort::class))->setArguments([
            new Reference(TaskApiTaskOperations::class),
            new Reference(TaskApiReadOperations::class),
            new Reference(CrmTaskBoardUserResolverInterface::class),
            $board,
            is_string($column) && $column !== '' ? $column : null,
        ]));
        $container->setAlias(CrmTaskPort::class, TaskBoardCrmTaskPort::class)->setPublic(true);
    }
}
