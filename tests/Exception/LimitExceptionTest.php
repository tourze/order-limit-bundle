<?php

declare(strict_types=1);

namespace OrderLimitBundle\Tests\Exception;

use OrderLimitBundle\Exception\LimitException;
use OrderLimitBundle\Exception\UserNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(LimitException::class)]
final class LimitExceptionTest extends AbstractExceptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This test doesn't require any setup
    }

    public function testExceptionIsCreatedWithMessage(): void
    {
        $exception = new UserNotFoundException('User not found');

        $this->assertSame('User not found', $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
    }

    public function testExceptionIsThrowable(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('Test user exception');

        throw new UserNotFoundException('Test user exception');
    }
}
