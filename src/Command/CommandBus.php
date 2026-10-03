<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Command;

use Hexagonal\Application\Command\Command;
use Hexagonal\Application\Port\Inbound\CommandBusInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CommandBus implements CommandBusInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function dispatch(Command $command): void
    {
        $this->messageBus->dispatch($command);
    }
}
