<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Middleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class SingleHandlerMiddleware extends HandleMessageMiddleware
{
    public function __construct(private HandlersLocatorInterface $locator, bool $allowNoHandlers = false)
    {
        parent::__construct($locator, $allowNoHandlers);
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $count = 0;
        foreach ($this->locator->getHandlers($envelope) as $_) {
            if (++$count > 1) {
                throw new \LogicException(sprintf('Multiple handlers for %s.', $envelope->getMessage()::class));
            }
        }
        if ($count === 0) {
            throw new NoHandlerForMessageException(sprintf('No handler for message "%s".', $envelope->getMessage()::class));
        }
        return parent::handle($envelope, $stack);
    }
}
