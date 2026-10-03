<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Query;

use Hexagonal\Application\DTO\DTO;
use Hexagonal\Application\Port\Inbound\QueryBusInterface;
use Hexagonal\Application\Query\Query;
use LogicException;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class QueryBus implements QueryBusInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function ask(Query $query): DTO
    {
        $envelope = $this->messageBus->dispatch($query);

        $handled = $envelope->all(HandledStamp::class);

        if (count($handled) !== 1) {
            throw new LogicException(
                sprintf(
                    'Query "%s" must have exactly one handler, %d handled it.',
                    $query::class,
                    count($handled),
                ),
            );
        }

        return $handled[0]->getResult();
    }
}
