<?php

namespace OrderLimitBundle\Tests\DependencyInjection;

use OrderLimitBundle\DependencyInjection\OrderLimitExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tourze\PHPUnitSymfonyUnitTest\AbstractDependencyInjectionExtensionTestCase;

/**
 * OrderLimitExtension 测试
 * 验证依赖注入扩展正确加载服务配置
 *
 * @internal
 */
#[CoversClass(OrderLimitExtension::class)]
final class OrderLimitExtensionTest extends AbstractDependencyInjectionExtensionTestCase
{
    public function testExtensionLoadsConfiguration(): void
    {
        $extension = new OrderLimitExtension();
        $container = new ContainerBuilder();

        // 设置必需的参数
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.debug', true);

        $extension->load([], $container);

        // 验证服务定义是否被加载
        $this->assertTrue($container->hasDefinition('OrderLimitBundle\Limit\CategoryLimitChecker'));
        $this->assertTrue($container->hasDefinition('OrderLimitBundle\Limit\SkuLimitChecker'));
        $this->assertTrue($container->hasDefinition('OrderLimitBundle\Limit\SpuLimitChecker'));
        $this->assertTrue($container->hasDefinition('OrderLimitBundle\Limit\TimeRangeCalculator'));
        $this->assertTrue($container->hasDefinition('OrderLimitBundle\Limit\LimitValidator'));
        $this->assertTrue($container->hasDefinition('OrderLimitBundle\Limit\DataExtractor'));
        $this->assertTrue($container->hasDefinition('OrderLimitBundle\Service\LimitService'));
    }

    public function testExtensionReturnsCorrectConfigDir(): void
    {
        $extension = new OrderLimitExtension();

        // 使用反射访问 protected 方法
        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('getConfigDir');
        $method->setAccessible(true);

        $configDir = $method->invoke($extension);

        $this->assertStringEndsWith('/Resources/config', $configDir);
        $this->assertStringContainsString('order-limit-bundle', $configDir);
    }
}
