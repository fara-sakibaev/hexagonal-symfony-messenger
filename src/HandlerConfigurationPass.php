<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger;

use Hexagonal\SymfonyMessenger\Middleware\SingleHandlerMiddleware;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

use function array_filter;
use function class_exists;
use function count;
use function implode;
use function in_array;
use function interface_exists;
use function method_exists;
use function sprintf;
use function str_ends_with;

final class HandlerConfigurationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach (['command.bus', 'query.bus'] as $bus) {
            if ($container->hasDefinition($bus.'.middleware.handle_message')) {
                $container->getDefinition($bus.'.middleware.handle_message')->setClass(SingleHandlerMiddleware::class);
            }
        }

        $counts = [];
        foreach ($container->findTaggedServiceIds('messenger.message_handler') as $id => $tags) {
            $configured = array_filter($tags);
            foreach ($configured ?: $tags as $tag) {
                $bus = $tag['bus'] ?? null;
                if (!in_array($bus, ['command.bus', 'query.bus'], true)) {
                    continue;
                }
                $class = $container->getDefinition($id)->getClass();
                if (null === $class || !class_exists($class)) {
                    continue;
                }
                $method = $tag['method'] ?? '__invoke';
                $message = $tag['handles'] ?? null;
                if (null === $message && method_exists($class, $method)) {
                    $type = new ReflectionMethod($class, $method)->getParameters()[0]->getType();
                    $message = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
                }
                if (null !== $message && !str_ends_with($message, '*') && !interface_exists($message)) {
                    $counts[$bus][$message][] = $id;
                }
            }
        }
        foreach ($counts as $bus => $messages) {
            foreach ($messages as $message => $ids) {
                if (count($ids) > 1) {
                    throw new RuntimeException(sprintf('%s has multiple handlers for %s: %s', $bus, $message, implode(', ', $ids)));
                }
            }
        }
    }
}
