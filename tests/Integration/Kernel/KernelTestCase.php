<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Integration\Kernel;

use Closure;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function count;
use function hash;
use function preg_replace;
use function serialize;

/**
 * Boots a fresh {@see TestKernel} per call, shut down after each test. A kernel booted with a fingerprint reuses the
 * container compiled for the same fingerprint earlier in the process ({@see TestKernel::sharedDir()}): a new kernel and
 * a new container instance (no service state carried over), without compiling the same container again. A kernel
 * without a fingerprint compiles its own container in its own cache directory, removed after the test.
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<TestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(TestKernel::baseDir());
    }

    /**
     * @param array<string, mixed> $config `nowo_crm_kit` configuration
     * @param Closure(ContainerConfigurator, string): void|null $configure host configuration; null = the standard host
     * @param bool $full SecurityBundle, UiKit and FormKit registered (false: Framework, Doctrine and Twig only)
     * @param bool $transactionBundle NowoTransactionDoctrineBundle registered
     * @param list<mixed>|null $fingerprint identifies everything `$configure` adds to the container (with the other
     *                                      arguments and the database URL it keys the shared container); null = a
     *                                      container of its own
     */
    protected function bootKernel(array $config = [], ?Closure $configure = null, bool $full = true, bool $transactionBundle = true, ?array $fingerprint = null): TestKernel
    {
        $shared          = $fingerprint === null ? null : $this->sharedId([$fingerprint, $config, $full, $transactionBundle, TestKernel::defaultDatabaseUrl()]);
        $id              = $shared ?? (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel          = new TestKernel($id, $config, $configure ?? Host::standard()->configure(), $full, $transactionBundle, $shared !== null);
        $this->kernels[] = $kernel;
        $kernel->boot();

        return $kernel;
    }

    /**
     * The fingerprint of a closure that configures the container: where it is defined and what it captured; null when
     * it is bound to an object (its behaviour may depend on that object's state).
     *
     * @return list<mixed>|null
     */
    protected static function closureFingerprint(?Closure $closure): ?array
    {
        if (!$closure instanceof Closure) {
            return [];
        }
        $function = new ReflectionFunction($closure);
        if ($function->getClosureThis() !== null) {
            return null;
        }

        return [$function->getFileName(), $function->getStartLine(), $function->getEndLine(), $function->getClosureUsedVariables()];
    }

    /**
     * @param list<mixed> $key
     */
    private function sharedId(array $key): ?string
    {
        try {
            return 'shared_' . hash('xxh128', serialize($key));
        } catch (Throwable) {
            // A captured closure or an anonymous class cannot be serialized: the kernel compiles its own container.
            return null;
        }
    }
}
