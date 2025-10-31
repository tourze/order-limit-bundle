<?php

namespace OrderLimitBundle;

use OrderCoreBundle\OrderCoreBundle;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Tourze\BundleDependency\BundleDependencyInterface;
use Tourze\ProductCoreBundle\ProductCoreBundle;
use Tourze\ProductLimitRuleBundle\ProductLimitRuleBundle;

class OrderLimitBundle extends Bundle implements BundleDependencyInterface
{
    public static function getBundleDependencies(): array
    {
        return [
            OrderCoreBundle::class => ['all' => true],
            ProductCoreBundle::class => ['all' => true],
            ProductLimitRuleBundle::class => ['all' => true],
        ];
    }
}
