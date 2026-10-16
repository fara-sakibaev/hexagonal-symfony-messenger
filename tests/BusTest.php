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
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

use function count;
use function sprintf;

final class BusTest extends TestCase
{
    public function testCommandIsDispatched(): void
    {
        $command = new readonly class extends Command {};
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::identicalTo($command))
            ->willReturn(new Envelope($command));

        new CommandBus($bus)->dispatch($command);
    }

    public function testQueryReturnsSingleHandlerResult(): void
    {
        $query = new readonly class extends Query {};
        $result = new readonly class extends DTO {};
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::identicalTo($query))
            ->willReturn(new Envelope($query, [new HandledStamp($result, 'handler')]));

        self::assertSame($result, new QueryBus($bus, $this->emptySenders())->ask($query));
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

    public function testQueryRejectsTransportBeforeDispatch(): void
    {
        $query = new readonly class extends Query {};
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $senders = $this->createMock(SendersLocatorInterface::class);
        $senders->expects(self::once())->method('getSenders')->willReturn(['async' => new stdClass()]);
        $this->expectException(LogicException::class);
        new QueryBus($bus, $senders)->ask($query);
    }

    private function assertHandlerCountRejected(array $stamps): void
    {
        $query = new readonly class extends Query {};
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::identicalTo($query))
            ->willReturn(new Envelope($query, $stamps));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('exactly one handler, %d handled it', count($stamps)));
        new QueryBus($bus, $this->emptySenders())->ask($query);
    }

    private function emptySenders(): SendersLocatorInterface
    {
        $senders = $this->createMock(SendersLocatorInterface::class);
        $senders->expects(self::once())->method('getSenders')->willReturn([]);

        return $senders;
    }
}
