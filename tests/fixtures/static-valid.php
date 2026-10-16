<?php

declare(strict_types=1);

namespace Hexagonal\SymfonyMessenger\Tests\Fixtures;

use Hexagonal\Application\DTO\DTO;
use Hexagonal\Application\Port\Inbound\QueryBusInterface;
use Hexagonal\Application\Query\Query;
use Hexagonal\Application\Query\QueryHandlerInterface;

use function PHPStan\Testing\assertType;

final readonly class UserDTO extends DTO
{
}

/** @extends Query<UserDTO> */
final readonly class FindUser extends Query
{
}

/** @implements QueryHandlerInterface<UserDTO, FindUser> */
final class FindUserHandler implements QueryHandlerInterface
{
    public function handle(FindUser $query): UserDTO
    {
        return new UserDTO();
    }
}

function checkResult(QueryBusInterface $bus): void
{
    assertType(UserDTO::class, $bus->ask(new FindUser()));
}
