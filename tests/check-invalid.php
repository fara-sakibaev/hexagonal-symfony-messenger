<?php

declare(strict_types=1);

$command = [PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--debug', '--no-progress', '--level', '8', '-c', 'phpstan.neon', 'vendor/fara-sakibaev/hexagonal-core/tests/fixtures/invalid-handlers.php'];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start PHPStan.');
}
$output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);

$normalized = preg_replace('/\s+/u', ' ', $output);
$expected = [
    'MissingCommandHandler implements Hexagonal\Application\Command\CommandHandlerInterface must declare public handle().',
    'WrongQueryHandler implements Hexagonal\Application\Query\QueryHandlerInterface handle() must return its declared TResult type.',
    'WrongDomainEventHandler implements Hexagonal\Application\Event\DomainEventHandlerInterface handle() must accept its declared TEvent type.',
    'MissingIntegrationEventHandler implements Hexagonal\Application\Event\IntegrationEventHandlerInterface must declare public handle().',
];
foreach ($expected as $diagnostic) {
    if (!str_contains($normalized, $diagnostic)) {
        fwrite(STDERR, "Missing expected PHPStan diagnostic: $diagnostic\n$output");
        exit(1);
    }
}
if (!str_contains($normalized, '[ERROR] Found 4 errors')) {
    fwrite(STDERR, "Unexpected PHPStan diagnostics\n$output");
    exit(1);
}
if ($status !== 1) {
    fwrite(STDERR, "Unexpected PHPStan exit code $status\n$output");
    exit(1);
}
echo count($expected).' expected handler diagnostics confirmed'.PHP_EOL;
