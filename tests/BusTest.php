<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Tests;

use Hexagonal\Application\Command\Command;
use Hexagonal\Application\DTO\DTO;
use Hexagonal\Application\Query\Query;
use Hexagonal\SymfonyMessenger\Command\CommandBus;
use Hexagonal\SymfonyMessenger\Query\QueryBus;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class BusTest extends TestCase
{
    public function testCommandIsDispatched(): void
    {
        $command = new readonly class extends Command {};
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::identicalTo($command))
            ->willReturn(new Envelope($command));

        (new CommandBus($bus))->dispatch($command);
    }

    public function testQueryReturnsSingleHandlerResult(): void
    {
        $query = new readonly class extends Query {};
        $result = new readonly class extends DTO {};
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::identicalTo($query))
            ->willReturn(new Envelope($query, [new HandledStamp($result, 'handler')]));

        self::assertSame($result, (new QueryBus($bus))->ask($query));
    }

    public function testQueryRejectsNoHandlers(): void
    {
        $this->assertHandlerCountRejected([]);
    }

    public function testQueryRejectsMultipleHandlers(): void
    {
        $result = new readonly class extends DTO {};
        $this->assertHandlerCountRejected([
            new HandledStamp($result, 'first'),
            new HandledStamp($result, 'second'),
        ]);
    }

    private function assertHandlerCountRejected(array $stamps): void
    {
        $query = new readonly class extends Query {};
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::identicalTo($query))
            ->willReturn(new Envelope($query, $stamps));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('exactly one handler, %d handled it', count($stamps)));
        (new QueryBus($bus))->ask($query);
    }
}
