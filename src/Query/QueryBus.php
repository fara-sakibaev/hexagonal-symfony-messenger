<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Query;

use Hexagonal\Application\DTO\DTO;
use Hexagonal\Application\Port\Inbound\QueryBusInterface;
use Hexagonal\Application\Query\Query;
use LogicException;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final readonly class QueryBus implements QueryBusInterface
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private SendersLocatorInterface $senders,
    ) {
    }

    /**
     * @template TResult of DTO
     * @param Query<TResult> $query
     * @return TResult
     * @throws ExceptionInterface
     */
    public function ask(Query $query): DTO
    {
        foreach ($this->senders->getSenders(new Envelope($query)) as $_) {
            throw new LogicException(sprintf('Query "%s" must not be routed to a transport.', $query::class));
        }

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
