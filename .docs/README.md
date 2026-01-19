# Contributte Messenger

Integration of [Symfony Messenger](https://symfony.com/doc/current/messenger.html) into [Nette Framework](https://nette.org).

## Content

- [Setup](#setup)
- [Minimal configuration](#minimal-configuration)
- [Full configuration](#full-configuration)
- [Messages](#messages)
- [Handlers](#handlers)
  - [Using attributes](#using-attributes)
  - [Using Neon tags](#using-neon-tags)
  - [Multiple handlers in one class](#multiple-handlers-in-one-class)
  - [Handler options](#handler-options)
- [Buses](#buses)
  - [Default buses](#default-buses)
  - [MessageBus](#messagebus)
  - [CommandBus](#commandbus)
  - [QueryBus](#querybus)
  - [Custom bus](#custom-bus)
  - [Bus registry](#bus-registry)
- [Transports](#transports)
  - [Sync transport](#sync-transport)
  - [In-memory transport](#in-memory-transport)
  - [Redis transport](#redis-transport)
  - [Doctrine transport](#doctrine-transport)
  - [AMQP transport](#amqp-transport)
  - [Custom transport factory](#custom-transport-factory)
- [Routing](#routing)
  - [Basic routing](#basic-routing)
  - [Interface routing](#interface-routing)
  - [Wildcard routing](#wildcard-routing)
- [Console commands](#console-commands)
  - [messenger:consume](#messengerconsume)
  - [messenger:debug](#messengerdebug)
  - [messenger:setup-transports](#messengersetup-transports)
  - [messenger:stats](#messengerstats)
  - [messenger:failed:show](#messengerfailedshow)
  - [messenger:failed:retry](#messengerfailedretry)
  - [messenger:failed:remove](#messengerfailedremove)
- [Advanced](#advanced)
  - [Retry strategy](#retry-strategy)
  - [Failure transport](#failure-transport)
  - [Middleware](#middleware)
  - [Batch handlers](#batch-handlers)
  - [Event listeners](#event-listeners)
  - [Serializers](#serializers)
  - [Logging](#logging)
- [Testing](#testing)
- [Examples](#examples)

---

## Setup

Install the package using [Composer](https://getcomposer.org):

```bash
composer require contributte/messenger
```

Register the extension in your Neon configuration:

```neon
extensions:
    messenger: Contributte\Messenger\DI\MessengerExtension
```

This package works best with these additional Contributte packages:

**Symfony Console** - provides console commands for consuming messages and managing transports:

```bash
composer require contributte/console
```

```neon
extensions:
    console: Contributte\Console\DI\ConsoleExtension(%consoleMode%)
```

**Symfony EventDispatcher** - provides lifecycle events:

```bash
composer require contributte/event-dispatcher
```

```neon
extensions:
    events: Contributte\EventDispatcher\DI\EventDispatcherExtension
```

---

## Minimal configuration

```neon
extensions:
    messenger: Contributte\Messenger\DI\MessengerExtension

messenger:
    transport:
        sync:
            dsn: sync://

    routing:
        App\Message\SendEmail: [sync]

services:
    - App\Handler\SendEmailHandler
```

---

## Full configuration

```neon
extensions:
    messenger: Contributte\Messenger\DI\MessengerExtension
    console: Contributte\Console\DI\ConsoleExtension(%consoleMode%)
    events: Contributte\EventDispatcher\DI\EventDispatcherExtension

messenger:
    # Debug panel (requires Tracy)
    debug:
        panel: %debugMode%

    # Message buses
    bus:
        messageBus:
            autowired: true
            defaultMiddlewares: true
            allowNoHandlers: false
            allowNoSenders: true
            middlewares: []

        commandBus:
            wrapper: Contributte\Messenger\Bus\CommandBus

        queryBus:
            wrapper: Contributte\Messenger\Bus\QueryBus

    # Serializers
    serializer:
        default: Symfony\Component\Messenger\Transport\Serialization\PhpSerializer

    # Transport factories
    transportFactory:
        sync: Symfony\Component\Messenger\Transport\Sync\SyncTransportFactory
        inMemory: Symfony\Component\Messenger\Transport\InMemory\InMemoryTransportFactory
        redis: Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory

    # Loggers
    logger:
        httpLogger: Psr\Log\NullLogger
        consoleLogger: Symfony\Component\Console\Logger\ConsoleLogger

    # Global failure transport
    failureTransport: failed

    # Transports
    transport:
        sync:
            dsn: sync://

        async:
            dsn: redis://localhost:6379/messages
            retryStrategy:
                maxRetries: 3
                delay: 1000
                multiplier: 2
                maxDelay: 60000
            failureTransport: failed

        failed:
            dsn: doctrine://default?queue_name=failed

    # Routing
    routing:
        App\Message\SendEmail: [async]
        App\Message\SendSms: [async]
        App\Message\LogEntry: [sync]
        "*": [sync]

services:
    - App\Handler\SendEmailHandler
    - App\Handler\SendSmsHandler
    - App\Handler\LogEntryHandler
```

---

## Messages

Messages are simple PHP objects (POPOs) that carry data. They don't need to extend any class or implement any interface.

```php
<?php declare(strict_types=1);

namespace App\Message;

final class SendEmail
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
    ) {
    }
}
```

> [!NOTE]
> Messages should be immutable and contain only the data needed for the handler to process them.

---

## Handlers

Handlers are services that process messages. Each handler must be registered in the DI container and marked as a message handler.

### Using attributes

The recommended way to define handlers is using the `#[AsMessageHandler]` attribute:

```php
<?php declare(strict_types=1);

namespace App\Handler;

use App\Message\SendEmail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendEmailHandler
{
    public function __invoke(SendEmail $message): void
    {
        // Send the email...
    }
}
```

Register the handler as a service:

```neon
services:
    - App\Handler\SendEmailHandler
```

> See [AsMessageHandler](https://symfony.com/doc/current/messenger.html#creating-a-message-handler) in Symfony documentation.

### Using Neon tags

Alternatively, you can use the `contributte.messenger.handler` tag:

```neon
services:
    -
        class: App\Handler\SendEmailHandler
        tags:
            contributte.messenger.handler:
```

With additional options:

```neon
services:
    -
        class: App\Handler\SendEmailHandler
        tags:
            contributte.messenger.handler:
                bus: messageBus
                alias: sendEmail
                method: __invoke
                handles: App\Message\SendEmail
                priority: 10
                from_transport: async
```

### Multiple handlers in one class

You can handle multiple message types in a single class:

**Using attributes:**

```php
<?php declare(strict_types=1);

namespace App\Handler;

use App\Message\SendEmail;
use App\Message\SendSms;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class NotificationHandler
{
    #[AsMessageHandler]
    public function handleEmail(SendEmail $message): void
    {
        // Send email...
    }

    #[AsMessageHandler]
    public function handleSms(SendSms $message): void
    {
        // Send SMS...
    }
}
```

**Using Neon tags:**

```neon
services:
    -
        class: App\Handler\NotificationHandler
        tags:
            contributte.messenger.handler:
                -
                    method: handleEmail
                -
                    method: handleSms
```

### Handler options

| Option | Description |
|--------|-------------|
| `bus` | Which bus this handler belongs to (default: all buses) |
| `alias` | Unique identifier for the handler |
| `method` | Method to call (default: `__invoke`) |
| `handles` | Message class to handle (auto-detected from method signature) |
| `priority` | Handler execution priority (higher = earlier, default: 0) |
| `from_transport` | Only handle messages from specific transport |

---

## Buses

A message bus is responsible for dispatching messages to their handlers. This package provides three pre-configured bus types.

### Default buses

By default, three buses are available:

- `messageBus` - General purpose message bus
- `commandBus` - For command messages (fire-and-forget)
- `queryBus` - For query messages (request-response)

### MessageBus

The generic message bus dispatches messages and returns an `Envelope`:

```php
<?php declare(strict_types=1);

use App\Message\SendEmail;
use Contributte\Messenger\Bus\MessageBus;

final class EmailController
{
    public function __construct(
        private MessageBus $messageBus,
    ) {
    }

    public function send(): void
    {
        $this->messageBus->dispatch(
            new SendEmail('john@example.com', 'Hello', 'World')
        );
    }
}
```

### CommandBus

The command bus is for fire-and-forget operations that don't return a result:

```php
<?php declare(strict_types=1);

use App\Message\CreateUser;
use Contributte\Messenger\Bus\CommandBus;

final class UserController
{
    public function __construct(
        private CommandBus $commandBus,
    ) {
    }

    public function create(): void
    {
        $this->commandBus->handle(
            new CreateUser('john@example.com', 'John Doe')
        );
    }
}
```

### QueryBus

The query bus is for operations that return a result:

```php
<?php declare(strict_types=1);

use App\Message\GetUser;
use Contributte\Messenger\Bus\QueryBus;

final class UserController
{
    public function __construct(
        private QueryBus $queryBus,
    ) {
    }

    public function detail(int $id): User
    {
        return $this->queryBus->query(new GetUser($id));
    }
}
```

The handler must return a value via the `HandledStamp`:

```php
<?php declare(strict_types=1);

use App\Message\GetUser;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GetUserHandler
{
    public function __invoke(GetUser $query): User
    {
        return $this->userRepository->find($query->id);
    }
}
```

### Custom bus

You can configure custom buses with specific middleware and options:

```neon
messenger:
    bus:
        eventBus:
            autowired: true
            allowNoHandlers: true
            allowNoSenders: true
            middlewares:
                - App\Middleware\LoggingMiddleware()

        customBus:
            class: App\Bus\CustomMessageBus
            wrapper: App\Bus\CustomBusWrapper
            defaultMiddlewares: false
            middlewares:
                - @validationMiddleware
```

| Option | Description |
|--------|-------------|
| `autowired` | Enable autowiring for this bus (default: true for first bus) |
| `allowNoHandlers` | Don't throw exception if no handler found (default: false) |
| `allowNoSenders` | Don't throw exception if no sender configured (default: true) |
| `defaultMiddlewares` | Include default middleware stack (default: true) |
| `middlewares` | Custom middleware to add |
| `class` | Custom bus class implementing `MessageBusInterface` |
| `wrapper` | Wrapper class for easy autowiring |

### Bus registry

Access any bus by name using the `BusRegistry`:

```php
<?php declare(strict_types=1);

use Contributte\Messenger\Bus\BusRegistry;

final class FlexibleController
{
    public function __construct(
        private BusRegistry $busRegistry,
    ) {
    }

    public function dispatch(string $busName, object $message): void
    {
        $bus = $this->busRegistry->get($busName);
        $bus->dispatch($message);
    }
}
```

---

## Transports

Transports define how messages are sent and received. Messages can be processed synchronously or asynchronously.

> See [Transports](https://symfony.com/doc/current/messenger.html#transports-async-queued-messages) in Symfony documentation.

### Sync transport

Process messages immediately (synchronously):

```neon
messenger:
    transport:
        sync:
            dsn: sync://
```

### In-memory transport

Store messages in memory (useful for testing):

```neon
messenger:
    transport:
        memory:
            dsn: in-memory://
```

### Redis transport

Process messages asynchronously via Redis:

```bash
composer require symfony/redis-messenger
```

```neon
messenger:
    transportFactory:
        redis: Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory

    transport:
        redis:
            dsn: redis://localhost:6379/messages
            options:
                stream: messenger
                group: default
                consumer: consumer-1
            serializer: default
```

### Doctrine transport

Process messages asynchronously via database:

```bash
composer require symfony/doctrine-messenger
```

```neon
messenger:
    transportFactory:
        doctrine: Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory

    transport:
        database:
            dsn: doctrine://default
            options:
                table_name: messenger_messages
                queue_name: default
                auto_setup: true
```

> [!IMPORTANT]
> The Doctrine transport requires a configured Doctrine DBAL connection. Use [nettrine/dbal](https://github.com/nettrine/dbal) for Nette integration.

### AMQP transport

Process messages asynchronously via RabbitMQ:

```bash
composer require symfony/amqp-messenger
```

```neon
messenger:
    transportFactory:
        amqp: Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory

    transport:
        rabbitmq:
            dsn: amqp://guest:guest@localhost:5672/%2f/messages
```

### Custom transport factory

Register a custom transport factory:

```neon
messenger:
    transportFactory:
        custom: App\Transport\CustomTransportFactory
```

```php
<?php declare(strict_types=1);

namespace App\Transport;

use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class CustomTransportFactory implements TransportFactoryInterface
{
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new CustomTransport($dsn, $options, $serializer);
    }

    public function supports(string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'custom://');
    }
}
```

---

## Routing

Routing defines which transport(s) should handle each message type.

> See [Routing](https://symfony.com/doc/current/messenger.html#routing-messages-to-a-transport) in Symfony documentation.

### Basic routing

Route messages to specific transports:

```neon
messenger:
    routing:
        App\Message\SendEmail: [async]
        App\Message\SendSms: [async, sync]
        App\Message\LogEntry: [sync]
```

> [!NOTE]
> If no route is defined for a message, it will be handled synchronously by dispatching it directly to handlers.

### Interface routing

Route all messages implementing an interface:

```php
<?php declare(strict_types=1);

namespace App\Message;

interface AsyncMessageInterface
{
}

final class SendEmail implements AsyncMessageInterface
{
    // ...
}
```

```neon
messenger:
    routing:
        App\Message\AsyncMessageInterface: [async]
```

### Wildcard routing

Route all messages matching a pattern:

```neon
messenger:
    routing:
        # Route all messages in namespace
        App\Message\*: [async]

        # Route all messages (fallback)
        "*": [sync]
```

---

## Console commands

The package provides several console commands for managing message queues.

### messenger:consume

Consume messages from one or more transports:

```bash
# Consume from single transport
bin/console messenger:consume async

# Consume from multiple transports
bin/console messenger:consume async redis

# Limit number of messages
bin/console messenger:consume async --limit=10

# Limit by time
bin/console messenger:consume async --time-limit=3600

# Limit by memory
bin/console messenger:consume async --memory-limit=128M

# Stop when queue is empty
bin/console messenger:consume async --stop-when-empty

# Run in verbose mode
bin/console messenger:consume async -vv
```

| Option | Description |
|--------|-------------|
| `--limit` | Maximum number of messages to consume |
| `--time-limit` | Maximum time in seconds to run |
| `--memory-limit` | Maximum memory to use (e.g., `128M`) |
| `--sleep` | Seconds to sleep when queue is empty (default: 1) |
| `--bus` | Specific bus to use |
| `--stop-when-empty` | Stop worker when queue is empty |

> See [messenger:consume](https://symfony.com/doc/current/messenger.html#consuming-messages-running-the-worker) in Symfony documentation.

### messenger:debug

Debug message routing and handlers:

```bash
bin/console messenger:debug
```

Output shows all registered messages, their handlers, and routing configuration.

### messenger:setup-transports

Create/update transport infrastructure (database tables, Redis streams, etc.):

```bash
# Setup all transports
bin/console messenger:setup-transports

# Setup specific transport
bin/console messenger:setup-transports async
```

### messenger:stats

Display transport statistics:

```bash
bin/console messenger:stats
```

### messenger:failed:show

Show failed messages:

```bash
# Show all failed messages
bin/console messenger:failed:show

# Show specific message details
bin/console messenger:failed:show 42
```

### messenger:failed:retry

Retry failed messages:

```bash
# Retry all failed messages
bin/console messenger:failed:retry

# Retry specific message
bin/console messenger:failed:retry 42

# Force retry without confirmation
bin/console messenger:failed:retry --force
```

### messenger:failed:remove

Remove failed messages:

```bash
# Remove specific message
bin/console messenger:failed:remove 42

# Remove all failed messages
bin/console messenger:failed:remove --all

# Force removal without confirmation
bin/console messenger:failed:remove 42 --force
```

---

## Advanced

### Retry strategy

Configure automatic retries for failed messages:

```neon
messenger:
    transport:
        async:
            dsn: redis://localhost:6379/messages
            retryStrategy:
                maxRetries: 3
                delay: 1000
                multiplier: 2
                maxDelay: 60000
```

| Option | Description |
|--------|-------------|
| `maxRetries` | Maximum number of retry attempts (default: 3) |
| `delay` | Initial delay in milliseconds (default: 1000) |
| `multiplier` | Delay multiplier for exponential backoff (default: 1) |
| `maxDelay` | Maximum delay in milliseconds (0 = unlimited) |
| `service` | Custom retry strategy service |

With `multiplier: 2` and `delay: 1000`, the delays will be: 1s, 2s, 4s, 8s, etc.

**Custom retry strategy:**

```php
<?php declare(strict_types=1);

namespace App\Retry;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;

final class CustomRetryStrategy implements RetryStrategyInterface
{
    public function isRetryable(Envelope $message, \Throwable $throwable = null): bool
    {
        // Custom logic...
        return true;
    }

    public function getWaitingTime(Envelope $message, \Throwable $throwable = null): int
    {
        // Return milliseconds to wait...
        return 5000;
    }
}
```

```neon
messenger:
    transport:
        async:
            dsn: redis://localhost:6379/messages
            retryStrategy:
                service: @App\Retry\CustomRetryStrategy

services:
    - App\Retry\CustomRetryStrategy
```

### Failure transport

Configure a transport for messages that fail after all retries:

```neon
messenger:
    # Global failure transport
    failureTransport: failed

    transport:
        async:
            dsn: redis://localhost:6379/messages
            # Per-transport failure transport (overrides global)
            failureTransport: failed

        failed:
            dsn: doctrine://default?queue_name=failed
```

> [!IMPORTANT]
> Always configure a failure transport to prevent losing messages. Use `messenger:failed:show` and `messenger:failed:retry` to manage failed messages.

### Middleware

Middleware allows you to hook into the message handling process:

```php
<?php declare(strict_types=1);

namespace App\Middleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->logger->info('Handling message', [
            'class' => get_class($envelope->getMessage()),
        ]);

        $envelope = $stack->next()->handle($envelope, $stack);

        $this->logger->info('Message handled', [
            'class' => get_class($envelope->getMessage()),
        ]);

        return $envelope;
    }
}
```

```neon
messenger:
    bus:
        messageBus:
            middlewares:
                - App\Middleware\LoggingMiddleware()
```

> See [Middleware](https://symfony.com/doc/current/messenger.html#middleware) in Symfony documentation.

### Batch handlers

Handle multiple messages in a single batch:

```php
<?php declare(strict_types=1);

namespace App\Handler;

use App\Message\SendEmail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\BatchHandlerTrait;

#[AsMessageHandler]
final class BatchEmailHandler implements BatchHandlerInterface
{
    use BatchHandlerTrait;

    private function process(array $jobs): void
    {
        // $jobs is array of [SendEmail $message, Acknowledger $ack]
        foreach ($jobs as [$message, $ack]) {
            try {
                $this->sendEmail($message);
                $ack->ack($message);
            } catch (\Throwable $e) {
                $ack->nack($e);
            }
        }
    }

    private function shouldFlush(): bool
    {
        return $this->getBatchSize() >= 10;
    }
}
```

> See [Extracting Results](https://symfony.com/doc/current/messenger.html#extracting-results) in Symfony documentation.

### Event listeners

Hook into the message lifecycle using Symfony EventDispatcher:

```php
<?php declare(strict_types=1);

namespace App\Listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

final class MessengerEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        // Log successful handling...
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        // Alert on failure...
    }
}
```

```neon
services:
    -
        class: App\Listener\MessengerEventSubscriber
        tags: [contributte.event_dispatcher.subscriber]
```

Available events:

| Event | Description |
|-------|-------------|
| `WorkerStartedEvent` | Worker has started |
| `WorkerRunningEvent` | Worker is running (each loop) |
| `WorkerStoppedEvent` | Worker has stopped |
| `WorkerMessageReceivedEvent` | Message received from transport |
| `WorkerMessageHandledEvent` | Message was handled successfully |
| `WorkerMessageFailedEvent` | Message handling failed |
| `WorkerMessageRetriedEvent` | Message is being retried |
| `SendMessageToTransportsEvent` | Message is being sent to transports |

### Serializers

Configure how messages are serialized for transport:

```neon
messenger:
    serializer:
        default: Symfony\Component\Messenger\Transport\Serialization\PhpSerializer
        json: Symfony\Component\Messenger\Transport\Serialization\Serializer

    transport:
        async:
            dsn: redis://localhost:6379/messages
            serializer: json
```

> [!NOTE]
> The default `PhpSerializer` uses PHP's native serialization. For interoperability with other systems, use the Symfony Serializer.

### Logging

Configure separate loggers for HTTP and CLI contexts:

```neon
messenger:
    logger:
        # Logger for web requests
        httpLogger: Psr\Log\NullLogger

        # Logger for console commands
        consoleLogger: Symfony\Component\Console\Logger\ConsoleLogger
```

The package automatically switches between loggers based on the execution context.

---

## Testing

Test message dispatch using the in-memory transport:

```neon
# config/test.neon
messenger:
    transport:
        async:
            dsn: in-memory://
```

```php
<?php declare(strict_types=1);

use App\Message\SendEmail;
use Nette\DI\Container;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Tester\Assert;
use Tester\TestCase;

final class EmailTest extends TestCase
{
    private Container $container;

    public function testEmailIsDispatched(): void
    {
        $bus = $this->container->getByType(MessageBus::class);
        $bus->dispatch(new SendEmail('test@example.com', 'Subject', 'Body'));

        /** @var InMemoryTransport $transport */
        $transport = $this->container->getService('messenger.transport.async');

        $messages = $transport->getSent();
        Assert::count(1, $messages);
        Assert::type(SendEmail::class, $messages[0]->getMessage());
    }
}
```

Use the `BufferLogger` for testing log output:

```php
<?php declare(strict_types=1);

use Contributte\Messenger\Logger\BufferLogger;
use Tester\Assert;

$logger = new BufferLogger();

// ... execute code that logs ...

$logs = $logger->obtain();
Assert::count(2, $logs);
Assert::contains('Message handled', $logs[0]['message']);
```

---

## Examples

- [contributte/messenger-skeleton](https://github.com/contributte/messenger-skeleton) - Minimal working example
- [contributte/examples](https://contributte.org/examples.html) - More examples

---

## Limitations

**Roadmap:**

- No `fallbackBus` in `RoutableMessageBus`
- Debug Tracy panel (TODO)

---

## Credits

This package is inspired by:

- [fmasa/messenger](https://github.com/fmasa/messenger)
- [symfony/messenger](https://github.com/symfony/messenger)
- [symfony/redis-messenger](https://github.com/symfony/redis-messenger)

Thank you to all contributors!
