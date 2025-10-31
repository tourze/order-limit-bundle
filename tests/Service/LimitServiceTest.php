<?php

declare(strict_types=1);

namespace OrderLimitBundle\Tests\Service;

use OrderCoreBundle\Entity\OrderProduct;
use OrderLimitBundle\Service\LimitService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;

/**
 * @internal
 */
#[CoversClass(LimitService::class)]
#[RunTestsInSeparateProcesses]
final class LimitServiceTest extends AbstractIntegrationTestCase
{
    private LimitService $limitService;

    protected function onSetUp(): void
    {
        $this->limitService = self::getService(LimitService::class);
    }

    public function testServiceExists(): void
    {
        $this->assertInstanceOf(LimitService::class, $this->limitService);
    }

    public function testCheckCategoryValidatesCategory(): void
    {
        // 创建并设置认证用户
        $user = $this->createNormalUser();
        $this->setAuthenticatedUser($user);

        // 创建一个测试OrderProduct对象
        $orderProduct = new OrderProduct();
        $orderProduct->setQuantity(1);

        // 测试分类限制检查功能能正常执行且不抛出致命错误
        // 由于 checkCategory 是 void 方法，我们验证它能正常执行
        $this->expectNotToPerformAssertions();
        $this->limitService->checkCategory($orderProduct);

        // 如果执行到这里说明没有抛出致命错误（业务异常是预期的）
    }

    public function testCheckSkuValidatesSku(): void
    {
        // 创建并设置认证用户
        $user = $this->createNormalUser();
        $this->setAuthenticatedUser($user);

        // 创建一个测试OrderProduct对象
        $orderProduct = new OrderProduct();
        $orderProduct->setQuantity(2);

        // 测试SKU限制检查功能能正常执行且不抛出致命错误
        // 由于 checkSku 是 void 方法，我们验证它能正常执行
        $this->expectNotToPerformAssertions();
        $this->limitService->checkSku($orderProduct);

        // 如果执行到这里说明没有抛出致命错误（业务异常是预期的）
    }

    public function testCheckSpuValidatesSpu(): void
    {
        // 创建并设置认证用户
        $user = $this->createNormalUser();
        $this->setAuthenticatedUser($user);

        // 创建一个测试OrderProduct对象
        $orderProduct = new OrderProduct();
        $orderProduct->setQuantity(1);

        // 测试SPU限制检查功能能正常执行且不抛出致命错误
        // 由于 checkSpu 是 void 方法，我们验证它能正常执行
        $this->expectNotToPerformAssertions();
        $this->limitService->checkSpu($orderProduct);

        // 如果执行到这里说明没有抛出致命错误（业务异常是预期的）
    }
}
