# Hexagonal Symfony Messenger

Symfony Messenger adapter for the buses and event publisher ports in `fara-sakibaev/hexagonal-core`. Application commands, queries, DTOs and events depend on core only. This package stays in the infrastructure layer.

## Install

Requires PHP 8.5, hexagonal-core 0.0.4 and Symfony Messenger, DependencyInjection and HttpKernel 7.4 or 8.1. Install with `composer require fara-sakibaev/hexagonal-symfony-messenger`. The compatible core release is `0.0.4`; this package does not require a neighboring core checkout.

Register `Hexagonal\SymfonyMessenger\HexagonalSymfonyMessengerBundle` alongside `FrameworkBundle`. Configure exactly these four named buses in the application:

```yaml
# config/packages/messenger.yaml
framework:
  messenger:
    default_bus: command.bus
    buses:
      command.bus: ~
      query.bus: ~
      domain_event.bus:
        default_middleware: allow_no_handlers
      integration_event.bus:
        default_middleware: allow_no_handlers
    transports:
      async: '%env(MESSENGER_TRANSPORT_DSN)%'
    routing:
      App\Application\Command\SendEmail: async
      App\Application\Event\OrderExported: async
```

`MESSENGER_TRANSPORT_DSN=amqp://guest:guest@localhost:5672/%2f/messages` is one RabbitMQ option after installing `symfony/amqp-messenger` and the required AMQP extension. Other Messenger transports work too. Keep queries and domain events out of transport routing. QueryBus rejects any sender discovered by Messenger before dispatch. Messenger middleware remains configurable per bus in the consuming application.

Register application handler classes as services with `autoconfigure: true`. A handler implements one core marker interface and exposes one public `handle()` with a concrete message parameter. No Symfony attribute or `__invoke()` is needed:

```php
/** @implements CommandHandlerInterface<CreateUser> */
final class CreateUserHandler implements CommandHandlerInterface
{
    public function handle(CreateUser $command): void { /* application work */ }
}

/** @implements QueryHandlerInterface<UserDTO, FindUser> */
final class FindUserHandler implements QueryHandlerInterface
{
    public function handle(FindUser $query): UserDTO { /* return DTO */ }
}
```

The bundle binds command, query, domain event and integration event handlers to their corresponding buses. It also aliases the four core ports to adapters. For example, inject `CommandBusInterface` and call `dispatch(new CreateUser(...))`, or inject `QueryBusInterface` and call `ask(new FindUser(...))`. Domain and integration publishers both accept variadic events: `$publisher->publish($first, $second)`. Define `/** @extends Query<UserDTO> */` on `FindUser` so PHPStan infers `UserDTO` from `ask()`.

Commands and queries need exactly one handler. Duplicate concrete registrations fail during container compilation; inherited, interface or transport specific matches are checked immediately before handlers execute on the handling side. Missing handlers raise Messenger's `NoHandlerForMessageException`. Handler exceptions remain `HandlerFailedException` with the original throwable in `getPrevious()` and Messenger's wrapped exceptions. Invalid query results fail at the PHP return type boundary. Transport send failures remain transport exceptions. Event buses allow zero or many handlers. Standard Messenger routing sends async commands and integration events; the worker then performs the handler check.

Core's `OutboxCoordinator` stores `OutboxRecord(eventId, DomainEvent)` alongside an aggregate. `IntegrationEventPublisherInterface` accepts `IntegrationEvent`, a different type. An application using `OutboxRelay` must implement `Outbox\EventPublisherInterface` to map each domain event record to an integration event, retain a stable event ID, and call the integration publisher. The application owns this mapping, storage transaction, relay scheduling and idempotency policy. A direct `publish()` call is not atomic with DB commit. Relay delivery may repeat after a crash between send and `markPublished`; consumers should be idempotent. This package does not promise exactly once delivery or implement a transport or outbox store.

The core PHPStan extension checks marker handler `handle()` signatures and query result types. Include its `phpstan-extension.neon` in application analysis. Symfony permits custom middleware on each named bus; preserve Messenger's `send_message` and `handle_message` stages when replacing defaults. A handler implementing multiple marker interfaces receives multiple bus tags. Keep handler roles distinct to avoid surprising cross bus handling.

## Minimal application

The `tests/BundleIntegrationTest.php` kernel is an executable example of bundle registration, service wiring, `handle()` discovery, four buses and port injection. Run it with `vendor/bin/phpunit tests/BundleIntegrationTest.php`.

## Migration

Replace handler `__invoke()` with `handle()` and add concrete parameter types. Configure four named buses and enable autoconfiguration for application handlers. QueryBus now requires Messenger's sender locator when instantiated manually; use the bundle alias in Symfony applications. PHP 8.5 and hexagonal-core 0.0.4 are required.
