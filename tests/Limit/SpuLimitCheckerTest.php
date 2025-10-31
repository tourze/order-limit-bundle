<?php

namespace OrderLimitBundle\Tests\Limit;

use OrderCoreBundle\Entity\Contract;
use OrderCoreBundle\Entity\OrderProduct;
use OrderLimitBundle\Limit\SpuLimitChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\ProductCoreBundle\Entity\Sku;
use Tourze\ProductCoreBundle\Entity\Spu;
use Tourze\ProductLimitRuleBundle\Entity\SpuLimitRule;
use Tourze\ProductLimitRuleBundle\Enum\SpuLimitType;

/**
 * SpuLimitChecker集成测试 - 基础功能验证
 *
 * @internal
 */
#[CoversClass(SpuLimitChecker::class)]
#[RunTestsInSeparateProcesses]
final class SpuLimitCheckerTest extends AbstractIntegrationTestCase
{
    private SpuLimitChecker $spuLimitChecker;

    protected function onSetUp(): void
    {
        $this->spuLimitChecker = self::getService(SpuLimitChecker::class);
    }

    public function testServiceExistsShouldReturnValidInstance(): void
    {
        $this->assertInstanceOf(SpuLimitChecker::class, $this->spuLimitChecker);
    }

    public function testCheckSpuLimitRuleWithValidInputsShouldExecute(): void
    {
        // 创建基本的测试数据
        $user = $this->createNormalUser();
        $this->setAuthenticatedUser($user);

        $limitRule = new SpuLimitRule();
        $limitRule->setType(SpuLimitType::BUY_TOTAL);
        $limitRule->setValue('10');

        $contract = new Contract();
        $contract->setUser($user);

        $spu = new Spu();
        // ID由Doctrine自动生成，不需要手动设置

        $sku = new Sku();
        // ID由Doctrine自动生成，不需要手动设置
        $sku->setSpu($spu);

        $orderProduct = new OrderProduct();
        $orderProduct->setQuantity(1);
        $orderProduct->setContract($contract);
        $orderProduct->setSku($sku);

        // 验证方法能正常执行而不抛出致命错误
        // checkSpuLimitRule 是 void 方法，我们验证它能正常执行
        $this->expectNotToPerformAssertions();
        $this->spuLimitChecker->checkSpuLimitRule($limitRule, $orderProduct);

        // 如果执行到这里说明没有抛出致命错误（业务异常是预期的）
    }
}
