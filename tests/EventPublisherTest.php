<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Tests;

use Hexagonal\Application\Event\IntegrationEvent;
use Hexagonal\Domain\DomainEvent;
use Hexagonal\SymfonyMessenger\Event\DomainEventPublisher;
use Hexagonal\SymfonyMessenger\Event\IntegrationEventPublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class EventPublisherTest extends TestCase
{
    public function testDomainEventsAreDispatchedInOrder(): void
    {
        $first = new readonly class extends DomainEvent {};
        $second = new readonly class extends DomainEvent {};
        $this->assertPublishedInOrder([ $first, $second ], static fn (MessageBusInterface $bus) => new DomainEventPublisher($bus));
    }

    public function testIntegrationEventsAreDispatchedInOrder(): void
    {
        $first = new readonly class extends IntegrationEvent {};
        $second = new readonly class extends IntegrationEvent {};
        $this->assertPublishedInOrder([ $first, $second ], static fn (MessageBusInterface $bus) => new IntegrationEventPublisher($bus));
    }

    public function testPublishingNoEventsDoesNotDispatch(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        (new DomainEventPublisher($bus))->publish();
        (new IntegrationEventPublisher($bus))->publish();
    }

    private function assertPublishedInOrder(array $events, callable $makePublisher): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $seen = [];
        $bus->expects(self::exactly(count($events)))->method('dispatch')
            ->willReturnCallback(static function (object $message) use (&$seen): Envelope {
                $seen[] = $message;
                return new Envelope($message);
            });

        $makePublisher($bus)->publish(...$events);

        self::assertSame($events, $seen);
    }
}
