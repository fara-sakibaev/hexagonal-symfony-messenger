<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Tests;

use Hexagonal\Application\Command\Command;
use Hexagonal\Application\Command\CommandHandlerInterface;
use Hexagonal\Application\DTO\DTO;
use Hexagonal\Application\Event\DomainEventHandlerInterface;
use Hexagonal\Application\Event\IntegrationEvent;
use Hexagonal\Application\Event\IntegrationEventHandlerInterface;
use Hexagonal\Application\Port\Inbound\CommandBusInterface;
use Hexagonal\Application\Port\Inbound\QueryBusInterface;
use Hexagonal\Application\Port\Outbound\DomainEventPublisherInterface;
use Hexagonal\Application\Port\Outbound\IntegrationEventPublisherInterface;
use Hexagonal\Application\Query\Query;
use Hexagonal\Application\Query\QueryHandlerInterface;
use Hexagonal\Domain\DomainEvent;
use Hexagonal\SymfonyMessenger\Command\CommandBus;
use Hexagonal\SymfonyMessenger\Event\DomainEventPublisher;
use Hexagonal\SymfonyMessenger\Event\IntegrationEventPublisher;
use Hexagonal\SymfonyMessenger\HexagonalSymfonyMessengerBundle;
use Hexagonal\SymfonyMessenger\Middleware\SingleHandlerMiddleware;
use Hexagonal\SymfonyMessenger\Query\QueryBus;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionException;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function in_array;
use function sys_get_temp_dir;

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
            self::assertSame([['bus' => $bus, 'method' => 'handle']], $tags['messenger.message_handler']);
        }
    }

    /**
     * @throws ReflectionException
     */
    public function testAdaptersResolveAndDispatchThroughNamedBuses(): void
    {
        $kernel = new BundleTestKernel('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $consumer = $container->get(BundleTestConsumer::class);
            self::assertInstanceOf(BundleTestConsumer::class, $consumer);

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

            $commandBus = $consumer->services[CommandBusInterface::class];
            self::assertInstanceOf(CommandBusInterface::class, $commandBus);
            $commandBus->dispatch(new BundleTestCommand());
            self::assertSame(1, BundleTestCommandHandler::$calls);

            $queryBus = $consumer->services[QueryBusInterface::class];
            self::assertInstanceOf(QueryBusInterface::class, $queryBus);
            $result = $queryBus->ask(new BundleTestQuery());
            self::assertInstanceOf(BundleTestResult::class, $result);
            self::assertSame('found', $result->value);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testMissingCommandFails(): void
    {
        $kernel = new BundleTestKernel('test', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $consumer = $container->get(BundleTestConsumer::class);
            self::assertInstanceOf(BundleTestConsumer::class, $consumer);
            $bus = $consumer->services[CommandBusInterface::class];
            self::assertInstanceOf(CommandBusInterface::class, $bus);
            $this->expectException(NoHandlerForMessageException::class);
            $bus->dispatch(new BundleMissingCommand());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testEventsUseTheirOwnBuses(): void
    {
        BundleTestDomainHandler::$calls = 0;
        BundleTestIntegrationHandler::$calls = 0;
        $kernel = new BundleTestKernel('test', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $consumer = $container->get(BundleTestConsumer::class);
            self::assertInstanceOf(BundleTestConsumer::class, $consumer);
            $services = $consumer->services;
            $domainPublisher = $services[DomainEventPublisherInterface::class];
            $integrationPublisher = $services[IntegrationEventPublisherInterface::class];
            self::assertInstanceOf(DomainEventPublisherInterface::class, $domainPublisher);
            self::assertInstanceOf(IntegrationEventPublisherInterface::class, $integrationPublisher);
            $domainPublisher->publish(new BundleTestDomainEvent(), new BundleTestDomainEvent());
            $integrationPublisher->publish(new BundleTestIntegrationEvent());
            self::assertSame(2, BundleTestDomainHandler::$calls);
            self::assertSame(1, BundleTestIntegrationHandler::$calls);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDuplicateCommandHandlersFailAtContainerCompile(): void
    {
        BundleTestCommandHandler::$calls = 0;
        BundleTestSecondCommandHandler::$calls = 0;
        $kernel = new BundleTestKernel('duplicate', true);
        try {
            $kernel->boot();
            self::fail('Expected duplicate handler error.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('multiple handlers', $error->getMessage());
            self::assertSame(0, BundleTestCommandHandler::$calls);
            self::assertSame(0, BundleTestSecondCommandHandler::$calls);
        }
    }

    public function testWorkerRejectsPolymorphicDuplicateBeforeHandling(): void
    {
        BundleTestCommandHandler::$calls = 0;
        BundleGenericCommandHandler::$calls = 0;
        $kernel = new BundleTestKernel('worker_duplicate', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $bus = $container->get('command.bus');
            self::assertInstanceOf(MessageBusInterface::class, $bus);
            self::assertInstanceOf(SingleHandlerMiddleware::class, $container->get('command.bus.middleware.handle_message'));
            try {
                $bus->dispatch(new Envelope(new BundleTestCommand(), [new ReceivedStamp('async')]));
                self::fail('Expected duplicate handler error.');
            } catch (LogicException $error) {
                self::assertStringContainsString('Multiple handlers', $error->getMessage());
                self::assertSame(0, BundleTestCommandHandler::$calls);
                self::assertSame(0, BundleGenericCommandHandler::$calls);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testQueryRoutingDoesNotSend(): void
    {
        $kernel = new BundleTestKernel('query_async', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $consumer = $container->get(BundleTestConsumer::class);
            self::assertInstanceOf(BundleTestConsumer::class, $consumer);
            $bus = $consumer->services[QueryBusInterface::class];
            self::assertInstanceOf(QueryBusInterface::class, $bus);
            try {
                $bus->ask(new BundleTestQuery());
                self::fail('Expected query routing error.');
            } catch (LogicException $error) {
                self::assertStringContainsString('must not be routed', $error->getMessage());
                $transport = $container->get('messenger.transport.async');
                self::assertInstanceOf(InMemoryTransport::class, $transport);
                self::assertCount(0, $transport->getSent());
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testQueryDuplicateDoesNotRunHandlers(): void
    {
        BundleTestQueryHandler::$calls = 0;
        BundleGenericQueryHandler::$calls = 0;
        $kernel = new BundleTestKernel('query_duplicate', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $consumer = $container->get(BundleTestConsumer::class);
            self::assertInstanceOf(BundleTestConsumer::class, $consumer);
            $bus = $consumer->services[QueryBusInterface::class];
            self::assertInstanceOf(QueryBusInterface::class, $bus);
            try {
                $bus->ask(new BundleTestQuery());
                self::fail('Expected duplicate query handlers.');
            } catch (LogicException $error) {
                self::assertStringContainsString('Multiple handlers', $error->getMessage());
                self::assertSame(0, BundleTestQueryHandler::$calls);
                self::assertSame(0, BundleGenericQueryHandler::$calls);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAsyncCommandIsSentWithoutRunningHandler(): void
    {
        BundleTestCommandHandler::$calls = 0;
        $kernel = new BundleTestKernel('async', true);
        $kernel->boot();
        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $consumer = $container->get(BundleTestConsumer::class);
            self::assertInstanceOf(BundleTestConsumer::class, $consumer);
            $commandBus = $consumer->services[CommandBusInterface::class];
            self::assertInstanceOf(CommandBusInterface::class, $commandBus);
            $commandBus->dispatch(new BundleTestCommand());
            self::assertSame(0, BundleTestCommandHandler::$calls);
            $transport = $container->get('messenger.transport.async');
            self::assertInstanceOf(InMemoryTransport::class, $transport);
            self::assertCount(1, $transport->getSent());
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
        $mode = $this->environment;
        $loader->load(static function (ContainerBuilder $container) use ($mode): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test-secret',
                'test' => true,
                'messenger' => ['default_bus' => 'command.bus', 'transports' => in_array($mode, ['async', 'query_async'], true) ? ['async' => 'in-memory://'] : [], 'routing' => match ($mode) {
                    'async' => [BundleTestCommand::class => 'async'], 'query_async' => [BundleTestQuery::class => 'async'], default => []
                }, 'buses' => [
                    'command.bus' => [],
                    'query.bus' => [],
                    'domain_event.bus' => [],
                    'integration_event.bus' => [],
                ]],
            ]);

            $container->register(BundleTestCommandHandler::class, BundleTestCommandHandler::class)->setAutoconfigured(true);
            if ('duplicate' === $mode) {
                $container->register(BundleTestSecondCommandHandler::class, BundleTestSecondCommandHandler::class)->setAutoconfigured(true);
            }
            if ('worker_duplicate' === $mode) {
                $container->register(BundleGenericCommandHandler::class, BundleGenericCommandHandler::class)->setAutoconfigured(true);
            }
            $container->register(BundleTestQueryHandler::class, BundleTestQueryHandler::class)->setAutoconfigured(true);
            if ('query_duplicate' === $mode) {
                $container->register(BundleGenericQueryHandler::class, BundleGenericQueryHandler::class)->setAutoconfigured(true);
            }
            $container->register(BundleTestDomainHandler::class, BundleTestDomainHandler::class)->setAutoconfigured(true);
            $container->register(BundleTestIntegrationHandler::class, BundleTestIntegrationHandler::class)->setAutoconfigured(true);
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
        return sys_get_temp_dir().'/hexagonal-symfony-messenger-test-cache-'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/hexagonal-symfony-messenger-test-logs';
    }
}

final class BundleTestConsumer
{
    /** @var array<class-string, object> */
    public array $services;

    /**
     * @param DomainEventPublisherInterface<DomainEvent>           $domainPublisher
     * @param IntegrationEventPublisherInterface<IntegrationEvent> $integrationPublisher
     */
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

final readonly class BundleTestCommand extends Command
{
}
final readonly class BundleMissingCommand extends Command
{
}

/** @implements CommandHandlerInterface<BundleTestCommand> */
final class BundleTestCommandHandler implements CommandHandlerInterface
{
    public static int $calls = 0;

    public function handle(BundleTestCommand $command): void
    {
        ++self::$calls;
    }
}

/** @implements CommandHandlerInterface<BundleTestCommand> */
final class BundleTestSecondCommandHandler implements CommandHandlerInterface
{
    public static int $calls = 0;

    public function handle(BundleTestCommand $command): void
    {
        ++self::$calls;
    }
}

/** @implements CommandHandlerInterface<Command> */
final class BundleGenericCommandHandler implements CommandHandlerInterface
{
    public static int $calls = 0;

    public function handle(Command $command): void
    {
        ++self::$calls;
    }
}

/** @extends Query<BundleTestResult> */
final readonly class BundleTestQuery extends Query
{
}

final readonly class BundleTestResult extends DTO
{
    public function __construct(public string $value)
    {
    }
}

/** @implements QueryHandlerInterface<BundleTestResult, BundleTestQuery> */
final class BundleTestQueryHandler implements QueryHandlerInterface
{
    public static int $calls = 0;

    public function handle(BundleTestQuery $query): BundleTestResult
    {
        ++self::$calls;

        return new BundleTestResult('found');
    }
}

/** @implements QueryHandlerInterface<DTO, Query<DTO>> */
final class BundleGenericQueryHandler implements QueryHandlerInterface
{
    public static int $calls = 0;

    /** @param Query<DTO> $query */
    public function handle(Query $query): DTO
    {
        ++self::$calls;

        return new BundleTestResult('generic');
    }
}

final readonly class BundleTestDomainEvent extends DomainEvent
{
}
final readonly class BundleTestIntegrationEvent extends IntegrationEvent
{
}

/** @implements DomainEventHandlerInterface<BundleTestDomainEvent> */
final class BundleTestDomainHandler implements DomainEventHandlerInterface
{
    public static int $calls = 0;

    public function handle(BundleTestDomainEvent $event): void
    {
        ++self::$calls;
    }
}

/** @implements IntegrationEventHandlerInterface<BundleTestIntegrationEvent> */
final class BundleTestIntegrationHandler implements IntegrationEventHandlerInterface
{
    public static int $calls = 0;

    public function handle(BundleTestIntegrationEvent $event): void
    {
        ++self::$calls;
    }
}
