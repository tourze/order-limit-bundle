<?php

declare(strict_types=1);

namespace OrderLimitBundle\Tests\Exception;

use OrderLimitBundle\Exception\UserNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(UserNotFoundException::class)]
final class UserNotFoundExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionWithMessage(): void
    {
        $message = 'User not found';
        $exception = new UserNotFoundException($message);

        self::assertSame($message, $exception->getMessage());
    }
}
