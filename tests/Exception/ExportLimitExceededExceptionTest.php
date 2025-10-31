<?php

declare(strict_types=1);

namespace OrderLimitBundle\Tests\Exception;

use OrderLimitBundle\Exception\ExportLimitExceededException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(ExportLimitExceededException::class)]
final class ExportLimitExceededExceptionTest extends AbstractExceptionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This test doesn't require any setup
    }

    public function testIsRuntimeException(): void
    {
        $exception = new ExportLimitExceededException();
        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testCanBeCreatedWithMessage(): void
    {
        $message = 'Export limit exceeded: 10000 rows';
        $exception = new ExportLimitExceededException($message);

        $this->assertSame($message, $exception->getMessage());
    }

    public function testCanBeCreatedWithMessageAndCode(): void
    {
        $message = 'Export limit exceeded';
        $code = 429;
        $exception = new ExportLimitExceededException($message, $code);

        $this->assertSame($message, $exception->getMessage());
        $this->assertSame($code, $exception->getCode());
    }

    public function testCanBeCreatedWithPreviousException(): void
    {
        $previous = new \Exception('Previous error');
        $exception = new ExportLimitExceededException('Export limit exceeded', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
