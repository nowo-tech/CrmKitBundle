<?php

declare(strict_types=1);

namespace Nowo\CrmKitBundle\Tests\Unit\Api;

use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiExceptionListener;
use Nowo\CrmKitBundle\Infrastructure\Symfony\Api\CrmApiRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Throwable;

/**
 * Audit VS-4: the firewall's exceptions and HTTP exceptions keep their own answers on the API routes.
 */
final class ApiExceptionListenerTest extends TestCase
{
    public function testSecurityAndHttpExceptionsPassThrough(): void
    {
        $listener = new CrmApiExceptionListener();
        foreach ([new AccessDeniedException(), new AuthenticationCredentialsNotFoundException(), new MethodNotAllowedHttpException(['GET'])] as $failure) {
            $event = $this->event($failure);
            $listener->onKernelException($event);
            self::assertNull($event->getResponse(), $failure::class);
        }
        $event = $this->event(new RuntimeException('x'));
        $listener->onKernelException($event);
        self::assertSame(500, $event->getResponse()?->getStatusCode());
    }

    private function event(Throwable $failure): ExceptionEvent
    {
        $request = new Request();
        $request->attributes->set(CrmApiRouteLoader::ROUTE_ATTRIBUTE, true);

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
    }
}
