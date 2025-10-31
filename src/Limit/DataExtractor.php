<?php

namespace OrderLimitBundle\Limit;

use Doctrine\Common\Collections\Collection;
use OrderCoreBundle\Entity\Contract;
use OrderCoreBundle\Entity\OrderProduct;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Tourze\ProductCoreBundle\Service\SkuService;
use Tourze\ProductCoreBundle\Service\SpuService;
use Tourze\ProductLimitRuleBundle\Enum\CategoryLimitType;
use Tourze\ProductLimitRuleBundle\Enum\SkuLimitType;
use Tourze\ProductLimitRuleBundle\Enum\SpuLimitType;

/**
 * 统一数据提取器 - 消除重复的null检查和数据获取逻辑
 */
#[Autoconfigure(public: true)]
readonly class DataExtractor
{
    public function __construct(
        private SkuService $skuService,
        private SpuService $spuService,
    ) {
    }

    /**
     * 安全获取限制规则的值
     */
    public function getLimitRuleValue(?string $value): int
    {
        return null !== $value ? intval($value) : 0;
    }

    /**
     * 安全调用对象的 getValue 方法
     */
    public function safeGetValue(object $rule): ?string
    {
        if (method_exists($rule, 'getValue')) {
            $value = $rule->getValue();

            return is_string($value) ? $value : null;
        }

        return null;
    }

    /**
     * 安全调用对象的 getType 方法
     */
    public function safeGetType(object $rule): CategoryLimitType|SkuLimitType|SpuLimitType|null
    {
        if (method_exists($rule, 'getType')) {
            $type = $rule->getType();
            if ($type instanceof CategoryLimitType || $type instanceof SkuLimitType || $type instanceof SpuLimitType) {
                return $type;
            }
        }

        return null;
    }

    /**
     * 从OrderProduct安全提取所有必需数据
     * 使用卫语句消除null检查的嵌套
     *
     * @return array{contract: Contract, user: mixed, sku: mixed, quantity: int}|null
     */
    public function extractOrderProductData(OrderProduct $orderProduct): ?array
    {
        $contract = $orderProduct->getContract();
        if (null === $contract) {
            return null;
        }

        $user = $contract->getUser();
        if (null === $user) {
            return null;
        }

        $sku = $orderProduct->getSku();
        if (null === $sku) {
            return null;
        }

        return [
            'contract' => $contract,
            'user' => $user,
            'sku' => $sku,
            'quantity' => $orderProduct->getQuantity(),
        ];
    }

    /**
     * 从SKU安全提取SPU
     */
    public function extractSpuFromSku(mixed $sku): ?object
    {
        if (null === $sku) {
            return null;
        }

        // 检查 $sku 是否是对象且有 getSpu 方法
        if (!is_object($sku) || !method_exists($sku, 'getSpu')) {
            return null;
        }

        $spu = $sku->getSpu();

        return is_object($spu) ? $spu : null;
    }

    /**
     * 获取SPU在订单中的总数量
     */
    public function calculateSpuQuantityInContract(Contract $contract, mixed $targetSpu): int
    {
        if (null === $targetSpu) {
            return 0;
        }

        // 检查 $targetSpu 是否是对象且有 getId 方法
        if (!is_object($targetSpu) || !method_exists($targetSpu, 'getId')) {
            return 0;
        }

        $totalQuantity = 0;
        foreach ($contract->getProducts() as $product) {
            $productSpu = $product->getSpu();
            if (null !== $productSpu && $productSpu->getId() === $targetSpu->getId()) {
                $totalQuantity += $product->getQuantity();
            }
        }

        return $totalQuantity;
    }

    /**
     * 获取分类在订单中的总数量
     */
    public function calculateCategoryQuantityInOrder(Contract $contract, mixed $targetCategory): int
    {
        // 检查 $targetCategory 是否是对象且有 getId 方法
        if (!is_object($targetCategory) || !method_exists($targetCategory, 'getId')) {
            return 0;
        }

        $totalQuantity = 0;

        foreach ($contract->getProducts() as $product) {
            $productSpu = $product->getSpu();
            if (null === $productSpu) {
                continue;
            }

            $categories = $this->getSpuCategories($productSpu);
            foreach ($categories as $category) {
                if (is_object($category) && method_exists($category, 'getId') && $category->getId() === $targetCategory->getId()) {
                    $totalQuantity += $product->getQuantity();
                }
            }
        }

        return $totalQuantity;
    }

    /**
     * 使用反射安全获取SPU分类
     * @return array<mixed>
     */
    public function getSpuCategories(mixed $spu): array
    {
        // 检查 $spu 是否是对象
        if (!is_object($spu)) {
            return [];
        }

        try {
            $reflectionClass = new \ReflectionClass($spu);
            $getCategoriesMethod = $reflectionClass->getMethod('getCategories');

            $result = $getCategoriesMethod->invoke($spu);

            // Convert Doctrine Collection to array
            if ($result instanceof Collection) {
                return $result->toArray();
            }

            // Handle array or other iterable types
            if (is_iterable($result)) {
                return is_array($result) ? $result : iterator_to_array($result);
            }

            return [];
        } catch (\ReflectionException) {
            return [];
        }
    }

    /**
     * 根据ID或GTIN查找SKU
     */
    public function findSkuByIdOrGtin(string $value): ?object
    {
        try {
            $method = new \ReflectionMethod($this->skuService, 'findSkuByIdOrGtin');

            $result = $method->invoke($this->skuService, $value);

            // 确保返回值符合声明的类型
            if (is_object($result)) {
                return $result;
            }

            return null;
        } catch (\ReflectionException) {
            return null;
        }
    }

    /**
     * 获取分类SPU ID的DQL
     */
    public function getSpuIdsByCategoryDQL(): string
    {
        try {
            $method = new \ReflectionMethod($this->spuService, 'getSpuIdsByCategoryDQL');

            $result = $method->invoke($this->spuService);

            // 确保返回值是字符串类型
            if (is_string($result)) {
                return $result;
            }

            return '';
        } catch (\ReflectionException) {
            return '';
        }
    }
}
