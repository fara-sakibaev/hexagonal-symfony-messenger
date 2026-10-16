<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Event;

use Hexagonal\Application\Event\IntegrationEvent;
use Hexagonal\Application\Port\Outbound\IntegrationEventPublisherInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** @implements IntegrationEventPublisherInterface<IntegrationEvent> */
final readonly class IntegrationEventPublisher implements IntegrationEventPublisherInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function publish(IntegrationEvent ...$event): void
    {
        foreach ($event as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }
    }
}
