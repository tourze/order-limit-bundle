<?php

declare(strict_types=1);

namespace OrderLimitBundle\Tests\Exception;

use OrderLimitBundle\Exception\ContractNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(ContractNotFoundException::class)]
final class ContractNotFoundExceptionTest extends AbstractExceptionTestCase
{
    public function testExceptionWithMessage(): void
    {
        $message = 'Contract not found';
        $exception = new ContractNotFoundException($message);

        self::assertSame($message, $exception->getMessage());
    }
}
