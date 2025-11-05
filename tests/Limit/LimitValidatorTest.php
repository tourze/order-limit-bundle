<?php

namespace OrderLimitBundle\Tests\Limit;

use OrderCoreBundle\Entity\Contract;
use OrderCoreBundle\Entity\OrderProduct;
use OrderLimitBundle\Exception\LimitRuleTriggerException;
use OrderLimitBundle\Limit\LimitValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\ProductLimitRuleBundle\Entity\SkuLimitRule;

/**
 * @internal
 */
#[CoversClass(LimitValidator::class)]
#[RunTestsInSeparateProcesses]
final class LimitValidatorTest extends AbstractIntegrationTestCase
{
    private LimitValidator $limitValidator;

    protected function onSetUp(): void
    {
        // 不需要调用 parent::onSetUp()，因为 AbstractIntegrationTestCase 的 onSetUp() 是抽象方法
        $this->limitValidator = self::getService(LimitValidator::class);
    }

    public function testServiceExists(): void
    {
        $this->assertInstanceOf(LimitValidator::class, $this->limitValidator);
    }

    public function testCheckMinQuantityWithValidQuantity(): void
    {
        $limitRule = $this->createMock(SkuLimitRule::class);
        $limitRule->method('getValue')->willReturn('5');

        $orderProduct = $this->createMock(OrderProduct::class);
        $orderProduct->method('getQuantity')->willReturn(10);

        $exception = null;
        try {
            $this->limitValidator->checkMinQuantity($limitRule, $orderProduct);
        } catch (LimitRuleTriggerException $e) {
            $exception = $e;
        }

        $this->assertNull($exception, 'Should not throw exception for valid quantity');
    }

    public function testCheckMinQuantityWithInvalidQuantity(): void
    {
        $limitRule = $this->createMock(SkuLimitRule::class);
        $limitRule->method('getValue')->willReturn('10');

        $orderProduct = $this->createMock(OrderProduct::class);
        $orderProduct->method('getQuantity')->willReturn(5);

        $this->expectException(LimitRuleTriggerException::class);
        $this->limitValidator->checkMinQuantity($limitRule, $orderProduct);
    }

    public function testValidateLimitWithCountExceedingLimit(): void
    {
        $limitRule = $this->createMock(SkuLimitRule::class);
        $limitRule->method('getValue')->willReturn('5');

        $orderProduct = $this->createMock(OrderProduct::class);
        $orderProduct->method('getQuantity')->willReturn(3);

        $this->expectException(LimitRuleTriggerException::class);
        $this->expectExceptionMessage('最多只能购买5件');
        $this->limitValidator->validateLimit(6, 3, $limitRule, $orderProduct, 'SKU');
    }

    public function testValidateLimitWithTotalExceedingLimit(): void
    {
        $limitRule = $this->createMock(SkuLimitRule::class);
        $limitRule->method('getValue')->willReturn('10');

        $orderProduct = $this->createMock(OrderProduct::class);
        $orderProduct->method('getQuantity')->willReturn(5);

        $this->expectException(LimitRuleTriggerException::class);
        $this->expectExceptionMessage('只能继续购买2件');
        $this->limitValidator->validateLimit(8, 5, $limitRule, $orderProduct, 'SKU');
    }

    public function testValidateLimitWithinLimits(): void
    {
        $limitRule = $this->createMock(SkuLimitRule::class);
        $limitRule->method('getValue')->willReturn('10');

        $orderProduct = $this->createMock(OrderProduct::class);
        $orderProduct->method('getQuantity')->willReturn(2);

        $exception = null;
        try {
            $this->limitValidator->validateLimit(5, 2, $limitRule, $orderProduct, 'SKU');
        } catch (LimitRuleTriggerException $e) {
            $exception = $e;
        }

        $this->assertNull($exception, 'Should not throw exception when within limits');
    }

    public function testHandleRestLimitErrorWithPositiveRest(): void
    {
        $limitRule = $this->createMock(SkuLimitRule::class);
        $limitRule->method('getValue')->willReturn('10');

        $orderProduct = $this->createMock(OrderProduct::class);
        $orderProduct->method('getQuantity')->willReturn(3);

        $this->expectException(LimitRuleTriggerException::class);
        $this->expectExceptionMessage('只能继续购买2件');
        $this->limitValidator->handleRestLimitError(2, 8, $orderProduct, $limitRule, 'SKU');
    }

    public function testHandleRestLimitErrorWithZeroRest(): void
    {
        $limitRule = $this->createMock(SkuLimitRule::class);
        $limitRule->method('getValue')->willReturn('10');

        $orderProduct = $this->createMock(OrderProduct::class);
        $orderProduct->method('getQuantity')->willReturn(3);

        $this->expectException(LimitRuleTriggerException::class);
        $this->expectExceptionMessage('已达到购买上限');
        $this->limitValidator->handleRestLimitError(0, 10, $orderProduct, $limitRule, 'SKU');
    }

    public function testCheckCouponLimitValidatesCouponRestrictions(): void
    {
        $limitRule = $this->createMock(SkuLimitRule::class);
        $limitRule->method('getValue')->willReturn('123,456');

        $contract = $this->createMock(Contract::class);

        // 检查优惠券限制功能能正常执行且不抛出异常
        $exception = null;
        try {
            $this->limitValidator->checkCouponLimit($limitRule, $contract);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        // 优惠券限制检查应该正常执行，如果抛出异常则说明实现有问题
        $this->assertNull($exception, 'Coupon limit check should execute without throwing exceptions for valid input');
    }
}
