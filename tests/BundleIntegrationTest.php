<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Tests;

use Hexagonal\Application\Command\Command;
use Hexagonal\Application\Command\CommandHandlerInterface;
use Hexagonal\Application\DTO\DTO;
use Hexagonal\Application\Event\DomainEventHandlerInterface;
use Hexagonal\Application\Event\IntegrationEventHandlerInterface;
use Hexagonal\Application\Port\Inbound\CommandBusInterface;
use Hexagonal\Application\Port\Inbound\QueryBusInterface;
use Hexagonal\Application\Port\Outbound\DomainEventPublisherInterface;
use Hexagonal\Application\Port\Outbound\IntegrationEventPublisherInterface;
use Hexagonal\Application\Query\Query;
use Hexagonal\Application\Query\QueryHandlerInterface;
use Hexagonal\SymfonyMessenger\Command\CommandBus;
use Hexagonal\SymfonyMessenger\Event\DomainEventPublisher;
use Hexagonal\SymfonyMessenger\Event\IntegrationEventPublisher;
use Hexagonal\SymfonyMessenger\HexagonalSymfonyMessengerBundle;
use Hexagonal\SymfonyMessenger\Query\QueryBus;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\MessageBusInterface;

final class BundleIntegrationTest extends TestCase
{
    public function testHandlerInterfacesAutoconfigureForTheirNamedBuses(): void
    {
        $container = new ContainerBuilder();
        new HexagonalSymfonyMessengerBundle()->build($container);

        foreach ([
            CommandHandlerInterface::class => 'command.bus',
            QueryHandlerInterface::class => 'query.bus',
            DomainEventHandlerInterface::class => 'domain_event.bus',
            IntegrationEventHandlerInterface::class => 'integration_event.bus',
        ] as $interface => $bus) {
            $tags = $container->getAutoconfiguredInstanceof()[$interface]->getTags();
            self::assertSame([['bus' => $bus]], $tags['messenger.message_handler']);
        }
    }

    /**
     * @throws \ReflectionException
     */
    public function testAdaptersResolveAndDispatchThroughNamedBuses(): void
    {
        $kernel = new BundleTestKernel('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            $consumer = $container->get(BundleTestConsumer::class);

            foreach ([
                CommandBusInterface::class => [CommandBus::class, 'command.bus'],
                QueryBusInterface::class => [QueryBus::class, 'query.bus'],
                DomainEventPublisherInterface::class => [DomainEventPublisher::class, 'domain_event.bus'],
                IntegrationEventPublisherInterface::class => [IntegrationEventPublisher::class, 'integration_event.bus'],
            ] as $interface => [$adapter, $bus]) {
                $service = $consumer->services[$interface];
                self::assertInstanceOf($adapter, $service);

                $property = new ReflectionProperty($adapter, 'messageBus');
                self::assertSame($container->get($bus), $property->getValue($service));
                self::assertInstanceOf(MessageBusInterface::class, $property->getValue($service));
            }

            $consumer->services[CommandBusInterface::class]->dispatch(new BundleTestCommand());
            self::assertSame(1, BundleTestCommandHandler::$calls);

            $result = $consumer->services[QueryBusInterface::class]->ask(new BundleTestQuery());
            self::assertInstanceOf(BundleTestResult::class, $result);
            self::assertSame('found', $result->value);
        } finally {
            $kernel->shutdown();
        }
    }
}

final class BundleTestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new HexagonalSymfonyMessengerBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test-secret',
                'test' => true,
                'messenger' => ['default_bus' => 'command.bus', 'buses' => [
                    'command.bus' => [],
                    'query.bus' => [],
                    'domain_event.bus' => [],
                    'integration_event.bus' => [],
                ]],
            ]);

            $container->register(BundleTestCommandHandler::class, BundleTestCommandHandler::class)->setAutoconfigured(true);
            $container->register(BundleTestQueryHandler::class, BundleTestQueryHandler::class)->setAutoconfigured(true);
            $container->register(BundleTestConsumer::class, BundleTestConsumer::class)
                ->setPublic(true)
                ->setArgument('$commandBus', new Reference(CommandBusInterface::class))
                ->setArgument('$queryBus', new Reference(QueryBusInterface::class))
                ->setArgument('$domainPublisher', new Reference(DomainEventPublisherInterface::class))
                ->setArgument('$integrationPublisher', new Reference(IntegrationEventPublisherInterface::class));
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/hexagonal-symfony-messenger-test-cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/hexagonal-symfony-messenger-test-logs';
    }
}

final class BundleTestConsumer
{
    public array $services;

    public function __construct(
        CommandBusInterface $commandBus,
        QueryBusInterface $queryBus,
        DomainEventPublisherInterface $domainPublisher,
        IntegrationEventPublisherInterface $integrationPublisher,
    ) {
        $this->services = [
            CommandBusInterface::class => $commandBus,
            QueryBusInterface::class => $queryBus,
            DomainEventPublisherInterface::class => $domainPublisher,
            IntegrationEventPublisherInterface::class => $integrationPublisher,
        ];
    }
}

final readonly class BundleTestCommand extends Command {}

final class BundleTestCommandHandler implements CommandHandlerInterface
{
    public static int $calls = 0;

    public function __invoke(BundleTestCommand $command): void
    {
        ++self::$calls;
    }
}

final readonly class BundleTestQuery extends Query {}

final readonly class BundleTestResult extends DTO
{
    public function __construct(public string $value) {}
}

final class BundleTestQueryHandler implements QueryHandlerInterface
{
    public function __invoke(BundleTestQuery $query): BundleTestResult
    {
        return new BundleTestResult('found');
    }
}
