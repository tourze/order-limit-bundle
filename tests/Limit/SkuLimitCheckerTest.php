<?php

namespace OrderLimitBundle\Tests\Limit;

use OrderCoreBundle\Entity\Contract;
use OrderCoreBundle\Entity\OrderProduct;
use OrderLimitBundle\Limit\SkuLimitChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\ProductCoreBundle\Entity\Sku;
use Tourze\ProductLimitRuleBundle\Entity\SkuLimitRule;
use Tourze\ProductLimitRuleBundle\Enum\SkuLimitType;

/**
 * SkuLimitChecker集成测试 - 基础功能验证
 *
 * @internal
 */
#[CoversClass(SkuLimitChecker::class)]
#[RunTestsInSeparateProcesses]
final class SkuLimitCheckerTest extends AbstractIntegrationTestCase
{
    private SkuLimitChecker $skuLimitChecker;

    protected function onSetUp(): void
    {
        $this->skuLimitChecker = self::getService(SkuLimitChecker::class);
    }

    public function testServiceExistsShouldReturnValidInstance(): void
    {
        $this->assertInstanceOf(SkuLimitChecker::class, $this->skuLimitChecker);
    }

    public function testCheckSkuLimitRuleWithValidInputsShouldExecute(): void
    {
        // 创建基本的测试数据
        $user = $this->createNormalUser();
        $this->setAuthenticatedUser($user);

        $limitRule = new SkuLimitRule();
        $limitRule->setType(SkuLimitType::BUY_TOTAL);
        $limitRule->setValue('10');

        $contract = new Contract();
        $contract->setUser($user);

        $sku = new Sku();
        // ID由Doctrine自动生成，不需要手动设置

        $orderProduct = new OrderProduct();
        $orderProduct->setQuantity(1);
        $orderProduct->setContract($contract);
        $orderProduct->setSku($sku);

        // 验证方法能正常执行而不抛出致命错误
        // checkSkuLimitRule 是 void 方法，我们验证它能正常执行
        $this->expectNotToPerformAssertions();
        $this->skuLimitChecker->checkSkuLimitRule($limitRule, $orderProduct);

        // 如果执行到这里说明没有抛出致命错误（业务异常是预期的）
    }
}
