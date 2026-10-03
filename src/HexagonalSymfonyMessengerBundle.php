<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger;

use Hexagonal\Application\Command\CommandHandlerInterface;
use Hexagonal\Application\Event\DomainEventHandlerInterface;
use Hexagonal\Application\Event\IntegrationEventHandlerInterface;
use Hexagonal\Application\Port\Inbound\CommandBusInterface;
use Hexagonal\Application\Port\Inbound\QueryBusInterface;
use Hexagonal\Application\Port\Outbound\DomainEventPublisherInterface;
use Hexagonal\Application\Port\Outbound\IntegrationEventPublisherInterface;
use Hexagonal\Application\Query\QueryHandlerInterface;
use Hexagonal\SymfonyMessenger\Command\CommandBus;
use Hexagonal\SymfonyMessenger\Event\DomainEventPublisher;
use Hexagonal\SymfonyMessenger\Event\IntegrationEventPublisher;
use Hexagonal\SymfonyMessenger\Query\QueryBus;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

final class HexagonalSymfonyMessengerBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        foreach ([
            CommandHandlerInterface::class => 'command.bus',
            QueryHandlerInterface::class => 'query.bus',
            DomainEventHandlerInterface::class => 'domain_event.bus',
            IntegrationEventHandlerInterface::class => 'integration_event.bus',
        ] as $interface => $bus) {
            $container->registerForAutoconfiguration($interface)
                ->addTag('messenger.message_handler', ['bus' => $bus]);
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $services = $configurator->services();

        $services->set(CommandBus::class)->arg('$messageBus', service('command.bus'));
        $services->set(QueryBus::class)->arg('$messageBus', service('query.bus'));
        $services->set(DomainEventPublisher::class)->arg('$messageBus', service('domain_event.bus'));
        $services->set(IntegrationEventPublisher::class)->arg('$messageBus', service('integration_event.bus'));

        $services->alias(CommandBusInterface::class, CommandBus::class);
        $services->alias(QueryBusInterface::class, QueryBus::class);
        $services->alias(DomainEventPublisherInterface::class, DomainEventPublisher::class);
        $services->alias(IntegrationEventPublisherInterface::class, IntegrationEventPublisher::class);
    }
}
