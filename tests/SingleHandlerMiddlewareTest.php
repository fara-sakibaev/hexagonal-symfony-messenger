<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Tests;

use Hexagonal\Application\Command\Command;
use Hexagonal\SymfonyMessenger\Middleware\SingleHandlerMiddleware;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class SingleHandlerMiddlewareTest extends TestCase
{
    public function testMultipleHandlersDoNotRun(): void
    {
        $calls = 0;
        $locator = new HandlersLocator([MiddlewareCommand::class => [
            new HandlerDescriptor(static function (MiddlewareCommand $command) use (&$calls): void { ++$calls; }, ['alias' => 'first']),
            new HandlerDescriptor(static function (MiddlewareCommand $command) use (&$calls): void { ++$calls; }, ['alias' => 'second']),
        ]]);
        try {
            new SingleHandlerMiddleware($locator)->handle(new Envelope(new MiddlewareCommand()), $this->stack());
            self::fail('Expected duplicate handler error.');
        } catch (LogicException $error) {
            self::assertStringContainsString('Multiple handlers', $error->getMessage());
            self::assertSame(0, $calls);
        }
    }

    public function testMissingHandlerFails(): void
    {
        $this->expectException(NoHandlerForMessageException::class);
        new SingleHandlerMiddleware(new HandlersLocator([]))->handle(new Envelope(new MiddlewareCommand()), $this->stack());
    }

    public function testHandlerFailureRetainsOriginalCause(): void
    {
        $cause = new RuntimeException('application failure');
        $locator = new HandlersLocator([MiddlewareCommand::class => [static function (MiddlewareCommand $command) use ($cause): void { throw $cause; }]]);
        try {
            new SingleHandlerMiddleware($locator)->handle(new Envelope(new MiddlewareCommand()), $this->stack());
            self::fail('Expected handler failure.');
        } catch (HandlerFailedException $error) {
            self::assertSame($cause, $error->getPrevious());
        }
    }

    private function stack(): StackInterface
    {
        $stack = $this->createMock(StackInterface::class);
        $stack->expects(self::never())->method('next');

        return $stack;
    }
}

final readonly class MiddlewareCommand extends Command
{
}
