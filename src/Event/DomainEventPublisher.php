<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Event;

use Hexagonal\Application\Port\Outbound\DomainEventPublisherInterface;
use Hexagonal\Domain\DomainEvent;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class DomainEventPublisher implements DomainEventPublisherInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    )
    {
    }

    /**
     * @throws ExceptionInterface
     */
    public function publish(DomainEvent ...$event): void
    {
        foreach ($event as $domainEvent) {
            $this->messageBus->dispatch($domainEvent);
        }
    }
}
