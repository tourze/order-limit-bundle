<?php

namespace OrderLimitBundle\DependencyInjection;

use Tourze\SymfonyDependencyServiceLoader\AutoExtension;

class OrderLimitExtension extends AutoExtension
{
    protected function getConfigDir(): string
    {
        return __DIR__ . '/../Resources/config';
    }
}
