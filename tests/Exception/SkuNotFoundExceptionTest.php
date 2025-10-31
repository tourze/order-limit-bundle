<?php

declare(strict_types=1);

namespace OrderLimitBundle\Tests\Exception;

use OrderLimitBundle\Exception\SkuNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(SkuNotFoundException::class)]
final class SkuNotFoundExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionWithMessage(): void
    {
        $message = 'SKU not found';
        $exception = new SkuNotFoundException($message);

        self::assertSame($message, $exception->getMessage());
    }
}
