<?php

declare(strict_types=1);

namespace Exception;

use OrderLimitBundle\Exception\LimitRuleTriggerException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;

/**
 * @internal
 */
#[CoversClass(LimitRuleTriggerException::class)]
final class LimitRuleTriggerExceptionTest extends AbstractExceptionTestCase
{
    protected function getExceptionClass(): string
    {
        return LimitRuleTriggerException::class;
    }

    public function testExceptionWithAllParameters(): void
    {
        $exception = new LimitRuleTriggerException(
            'CategoryLimit',
            'category-123',
            '10',
            '15',
            'Custom error message'
        );

        $this->assertEquals('CategoryLimit', $exception->getRuleType());
        $this->assertEquals('category-123', $exception->getEntityId());
        $this->assertEquals('10', $exception->getLimitValue());
        $this->assertEquals('15', $exception->getCurrentValue());
        $this->assertEquals('Custom error message', $exception->getMessage());
    }

    public function testExceptionWithDefaultMessage(): void
    {
        $exception = new LimitRuleTriggerException(
            'SkuLimit',
            'sku-456',
            '5',
            '8'
        );

        $this->assertEquals('SkuLimit', $exception->getRuleType());
        $this->assertEquals('sku-456', $exception->getEntityId());
        $this->assertEquals('5', $exception->getLimitValue());
        $this->assertEquals('8', $exception->getCurrentValue());
        $this->assertStringContainsString('限购规则触发: SkuLimit', $exception->getMessage());
        $this->assertStringContainsString('sku-456', $exception->getMessage());
        $this->assertStringContainsString('限制值: 5', $exception->getMessage());
        $this->assertStringContainsString('当前值: 8', $exception->getMessage());
    }

    public function testExceptionWithoutCurrentValue(): void
    {
        $exception = new LimitRuleTriggerException(
            'SpuLimit',
            'spu-789',
            '3'
        );

        $this->assertEquals('SpuLimit', $exception->getRuleType());
        $this->assertEquals('spu-789', $exception->getEntityId());
        $this->assertEquals('3', $exception->getLimitValue());
        $this->assertEquals('', $exception->getCurrentValue());
        $this->assertStringContainsString('限购规则触发: SpuLimit', $exception->getMessage());
        $this->assertStringNotContainsString('当前值', $exception->getMessage());
    }
}
