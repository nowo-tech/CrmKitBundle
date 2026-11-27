<?php

declare(strict_types=1);

// Minimal stand-ins of nowo-tech/mail-kit-bundle's stored templates API (2.7.0), loaded by tests/bootstrap.php only when
// MailKit is not installed: the names, signatures and guards the CRM's MailKit bridge uses (src/Bridge/MailKit). The
// guards are simplified; the bridge passes values MailKit's own guards accept.

namespace Nowo\MailKitBundle\Template\Exception {
    use InvalidArgumentException;
    use Nowo\MailKitBundle\Template\Value\TemplatePermission;
    use RuntimeException;
    use Throwable;

    interface TemplateException extends Throwable
    {
    }

    final class TemplateNotFound extends RuntimeException implements TemplateException
    {
        public function __construct()
        {
            parent::__construct('Template not found.');
        }
    }

    final class TemplateAccessDenied extends RuntimeException implements TemplateException
    {
        public function __construct(public readonly TemplatePermission $permission)
        {
            parent::__construct('Template access denied (' . $permission->value . ').');
        }
    }

    final class TemplateVersionConflict extends RuntimeException implements TemplateException
    {
        public function __construct(public readonly int $expected)
        {
            parent::__construct('The template was changed by someone else.');
        }
    }

    final class TemplateKeyTaken extends RuntimeException implements TemplateException
    {
        public function __construct()
        {
            parent::__construct('A template with this key and locale exists already.');
        }
    }

    final class InvalidTemplate extends InvalidArgumentException implements TemplateException
    {
        public function __construct(public readonly string $field, string $message)
        {
            parent::__construct($message);
        }
    }
}

namespace Nowo\MailKitBundle\Template\Value {
    use DateTimeImmutable;
    use InvalidArgumentException;
    use Nowo\MailKitBundle\Mailer\MailHeaders;
    use Nowo\MailKitBundle\Template\Exception\InvalidTemplate;
    use Symfony\Component\Mime\Email;

    use function preg_match;
    use function trim;

    enum TemplateKind: string
    {
        case Template = 'template';
        case Snippet  = 'snippet';
    }

    enum TemplateScope: string
    {
        case Personal = 'personal';
        case Shared   = 'shared';
    }

    enum TemplatePermission: string
    {
        case Personal     = 'personal';
        case UseShared    = 'use_shared';
        case ManageShared = 'manage_shared';
    }

    final readonly class TemplateActor
    {
        public const REF_PATTERN = '/^[A-Za-z0-9_.:@-]{1,128}\z/';

        public function __construct(public string $tenant, public string $owner)
        {
            self::assertRef($tenant, 'tenant');
            self::assertRef($owner, 'owner');
        }

        public static function assertRef(string $ref, string $what = 'owner'): void
        {
            if (preg_match(self::REF_PATTERN, $ref) !== 1) {
                throw new InvalidArgumentException('A template ' . $what . ' reference is invalid.');
            }
        }
    }

    final readonly class TemplateDraft
    {
        public const KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}\z/';

        public const LOCALE_PATTERN = '/^[a-z]{2,3}(?:_[A-Z]{2})?\z/';

        public string $name;

        public function __construct(
            public TemplateKind $kind,
            public TemplateScope $scope,
            public string $key,
            string $name,
            public string $locale,
            public string $subject,
            public string $html,
            public string $text,
        ) {
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new InvalidTemplate('key', 'Invalid key.');
            }
            if (preg_match(self::LOCALE_PATTERN, $locale) !== 1) {
                throw new InvalidTemplate('locale', 'Invalid locale.');
            }
            if (trim($name) === '') {
                throw new InvalidTemplate('name', 'Invalid name.');
            }
            $this->name = trim($name);
            if ($kind === TemplateKind::Template && trim($subject) === '') {
                throw new InvalidTemplate('subject', 'Invalid subject.');
            }
            if (trim($html) === '' && trim($text) === '') {
                throw new InvalidTemplate('body', 'Empty body.');
            }
        }
    }

    final readonly class TemplateSummary
    {
        public function __construct(
            public string $id,
            public TemplateKind $kind,
            public TemplateScope $scope,
            public ?string $owner,
            public string $key,
            public string $name,
            public string $locale,
            public string $subject,
            public int $version,
            public ?string $updatedBy,
            public DateTimeImmutable $updatedAt,
        ) {
        }
    }

    final readonly class StoredTemplate
    {
        public function __construct(
            public string $id,
            public TemplateKind $kind,
            public TemplateScope $scope,
            public string $tenant,
            public ?string $owner,
            public string $key,
            public string $name,
            public string $locale,
            public string $subject,
            public string $html,
            public string $text,
            public int $version,
            public ?string $createdBy,
            public ?string $updatedBy,
            public DateTimeImmutable $createdAt,
            public DateTimeImmutable $updatedAt,
        ) {
        }

        public function summary(): TemplateSummary
        {
            return new TemplateSummary($this->id, $this->kind, $this->scope, $this->owner, $this->key, $this->name, $this->locale, $this->subject, $this->version, $this->updatedBy, $this->updatedAt);
        }
    }

    final readonly class RenderedTemplate
    {
        /**
         * @param list<string> $unresolved
         */
        public function __construct(
            public ?string $templateId,
            public TemplateKind $kind,
            public string $key,
            public string $locale,
            public string $subject,
            public string $html,
            public string $text,
            public array $unresolved = [],
        ) {
        }

        public function applyTo(Email $email, bool $tag = true): Email
        {
            if ($this->kind === TemplateKind::Template) {
                $email->subject($this->subject);
            }
            if ($this->html !== '') {
                $email->html($this->html);
            }
            $email->text($this->text);
            if ($tag) {
                MailHeaders::tag($email, $this->key);
            }

            return $email;
        }
    }
}

namespace Nowo\MailKitBundle\Template\Placeholder {
    use InvalidArgumentException;
    use Nowo\MailKitBundle\Template\Value\TemplateActor;

    use function preg_match;

    interface PlaceholderProviderInterface
    {
        public const TAG = 'nowo_mail_kit.template_placeholder_provider';

        public const NAMESPACE_PATTERN = '/^[a-z][a-z0-9_]{0,31}\z/';

        public const NAME_PATTERN = '/^[a-z0-9_]{1,40}\z/';

        public static function placeholderNamespace(): string;

        /**
         * @return list<PlaceholderDefinition>
         */
        public function definitions(): array;

        /**
         * @param list<string> $names
         *
         * @return array<string, string>
         */
        public function resolve(array $names, PlaceholderContext $context): array;
    }

    final readonly class PlaceholderDefinition
    {
        public function __construct(public string $name, public string $label)
        {
            if (preg_match(PlaceholderProviderInterface::NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException('Invalid placeholder name.');
            }
        }
    }

    final readonly class PlaceholderContext
    {
        public const MAX_SUBJECTS = 16;

        /**
         * @param array<string, string> $subjects
         */
        public function __construct(public TemplateActor $actor, public string $locale, public array $subjects = [])
        {
            if (\count($subjects) > self::MAX_SUBJECTS) {
                throw new InvalidArgumentException('Too many subject references.');
            }
            foreach ($subjects as $name => $ref) {
                if (preg_match(PlaceholderProviderInterface::NAMESPACE_PATTERN, $name) !== 1 || preg_match(TemplateActor::REF_PATTERN, $ref) !== 1) {
                    throw new InvalidArgumentException('Invalid subject reference.');
                }
            }
        }

        public function subject(string $name): ?string
        {
            return $this->subjects[$name] ?? null;
        }
    }
}

namespace Nowo\MailKitBundle\Template\Port {
    use Nowo\MailKitBundle\Template\Value\TemplateActor;
    use Nowo\MailKitBundle\Template\Value\TemplatePermission;

    interface TemplatePermissionInterface
    {
        public function isGranted(TemplatePermission $permission, TemplateActor $actor): bool;
    }
}

namespace Nowo\MailKitBundle\Template\Service {
    use Nowo\MailKitBundle\Template\Placeholder\PlaceholderDefinition;
    use Nowo\MailKitBundle\Template\Value\RenderedTemplate;
    use Nowo\MailKitBundle\Template\Value\StoredTemplate;
    use Nowo\MailKitBundle\Template\Value\TemplateActor;
    use Nowo\MailKitBundle\Template\Value\TemplateDraft;
    use Nowo\MailKitBundle\Template\Value\TemplateKind;
    use Nowo\MailKitBundle\Template\Value\TemplateScope;
    use Nowo\MailKitBundle\Template\Value\TemplateSummary;

    interface TemplateLibraryInterface
    {
        /**
         * @return list<TemplateSummary>
         */
        public function list(TemplateActor $actor, TemplateKind $kind, ?TemplateScope $scope = null, ?string $locale = null): array;

        public function get(TemplateActor $actor, string $id): StoredTemplate;

        public function findByKey(TemplateActor $actor, TemplateKind $kind, TemplateScope $scope, string $key, string $locale): StoredTemplate;

        public function create(TemplateActor $actor, TemplateDraft $draft): StoredTemplate;

        public function update(TemplateActor $actor, string $id, int $expectedVersion, TemplateDraft $draft): StoredTemplate;

        public function delete(TemplateActor $actor, string $id, int $expectedVersion): void;
    }

    interface TemplateRendererInterface
    {
        /**
         * @param array<string, string> $subjects
         */
        public function render(TemplateActor $actor, string $id, array $subjects = []): RenderedTemplate;

        /**
         * @param array<string, string> $subjects
         */
        public function renderDraft(TemplateActor $actor, TemplateDraft $draft, array $subjects = []): RenderedTemplate;

        /**
         * @return array<string, list<PlaceholderDefinition>>
         */
        public function placeholders(): array;
    }
}

namespace Nowo\MailKitBundle\Template\Event {
    use Nowo\MailKitBundle\Template\Value\TemplateKind;
    use Nowo\MailKitBundle\Template\Value\TemplateScope;

    final readonly class TemplateDeletedEvent
    {
        public function __construct(public string $templateId, public string $tenant, public TemplateKind $kind, public TemplateScope $scope)
        {
        }
    }
}

namespace Nowo\MailKitBundle\Mailer {
    use Symfony\Component\Mime\Message;

    final class MailHeaders
    {
        public const TAG = 'X-Mail-Kit-Tag';

        public static function tag(Message $message, string $tag): void
        {
            $message->getHeaders()->remove(self::TAG);
            $message->getHeaders()->addTextHeader(self::TAG, $tag);
        }
    }
}
