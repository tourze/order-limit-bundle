<?php

declare(strict_types=1);

namespace OrderLimitBundle\Tests;

use OrderLimitBundle\OrderLimitBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractBundleTestCase;

/**
 * @internal
 */
#[CoversClass(OrderLimitBundle::class)]
#[RunTestsInSeparateProcesses]
final class OrderLimitBundleTest extends AbstractBundleTestCase
{
    public function testBundleDependencies(): void
    {
        $dependencies = OrderLimitBundle::getBundleDependencies();

        self::assertIsArray($dependencies);
        self::assertArrayHasKey('OrderCoreBundle\OrderCoreBundle', $dependencies);
        self::assertArrayHasKey('Tourze\ProductCoreBundle\ProductCoreBundle', $dependencies);
        self::assertArrayHasKey('Tourze\ProductLimitRuleBundle\ProductLimitRuleBundle', $dependencies);
    }
}
