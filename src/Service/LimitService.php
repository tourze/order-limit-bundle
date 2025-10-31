<?php

namespace OrderLimitBundle\Service;

use Monolog\Attribute\WithMonologChannel;
use OrderCoreBundle\Entity\OrderProduct;
use OrderCoreBundle\Repository\ContractRepository;
use OrderCoreBundle\Repository\OrderProductRepository;
use OrderLimitBundle\Limit\CategoryLimitChecker;
use OrderLimitBundle\Limit\DataExtractor;
use OrderLimitBundle\Limit\LimitValidator;
use OrderLimitBundle\Limit\QueryBuilder;
use OrderLimitBundle\Limit\SkuLimitChecker;
use OrderLimitBundle\Limit\SpuLimitChecker;
use OrderLimitBundle\Limit\TimeRangeCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Tourze\ProductCoreBundle\Service\SkuService;
use Tourze\ProductCoreBundle\Service\SpuService;
use Tourze\ProductLimitRuleBundle\Service\LimitRuleService;

/**
 * 限购服务 - 重构后的简化版本
 * 使用Linus "好品味"原则：消除特殊情况，减少复杂度
 *
 * 重构后的核心改进：
 * - 将复杂逻辑拆分为专门的检查器类
 * - 使用统一的数据提取器消除重复的null检查
 * - 将原来的79认知复杂度降低到约15
 * - 保持公共API完全不变，确保向后兼容
 */
#[Autoconfigure(public: true)]
#[WithMonologChannel(channel: 'order_limit')]
readonly class LimitService
{
    private DataExtractor $dataExtractor;

    private SkuLimitChecker $skuLimitChecker;

    private SpuLimitChecker $spuLimitChecker;

    private CategoryLimitChecker $categoryLimitChecker;

    public function __construct(
        private LoggerInterface $logger,
        SpuService $spuService,
        SkuService $skuService,
        ContractRepository $contractRepository,
        OrderProductRepository $orderProductRepository,
        private readonly ?LimitRuleService $limitRuleService = null,
    ) {
        // 初始化所有组件 - 清晰的依赖关系
        $this->dataExtractor = new DataExtractor($skuService, $spuService);
        $queryBuilder = new QueryBuilder($contractRepository, $orderProductRepository);
        $timeRangeCalculator = new TimeRangeCalculator();
        $limitValidator = new LimitValidator($logger);

        $this->skuLimitChecker = new SkuLimitChecker($logger, $queryBuilder, $timeRangeCalculator, $limitValidator, $this->dataExtractor);
        $this->spuLimitChecker = new SpuLimitChecker($logger, $queryBuilder, $timeRangeCalculator, $limitValidator, $this->dataExtractor, $spuService, $contractRepository, $orderProductRepository);
        $this->categoryLimitChecker = new CategoryLimitChecker($logger, $timeRangeCalculator, $limitValidator, $this->dataExtractor, $contractRepository, $orderProductRepository);
    }

    /**
     * 根据SPU配置，检查用户是否可以购买指定SKU
     * 简化后只需要委托给专门的检查器
     */
    public function checkSpu(OrderProduct $orderProduct): void
    {
        if (null === $this->limitRuleService) {
            return;
        }

        $data = $this->dataExtractor->extractOrderProductData($orderProduct);
        if (null === $data) {
            return;
        }

        $spu = $this->dataExtractor->extractSpuFromSku($data['sku']);
        if (null === $spu) {
            return;
        }

        $spuId = $spu->getId();
        $spuIdStr = match (true) {
            is_string($spuId) => $spuId,
            is_int($spuId) => (string) $spuId,
            is_float($spuId) => (string) $spuId,
            default => 'unknown',
        };
        $spuLimitRules = $this->limitRuleService->findSpuLimitRulesBySpuId($spuIdStr);
        foreach ($spuLimitRules as $limitRule) {
            $this->spuLimitChecker->checkSpuLimitRule($limitRule, $orderProduct);
        }
    }

    /**
     * 根据SKU配置，检查用户是否可以购买指定SKU
     * 简化后只需要委托给专门的检查器
     */
    public function checkSku(OrderProduct $orderProduct): void
    {
        if (null === $this->limitRuleService) {
            return;
        }

        $data = $this->dataExtractor->extractOrderProductData($orderProduct);
        if (null === $data) {
            return;
        }

        // 类型安全检查：确保 $data['sku'] 有 getId 方法
        if (!is_object($data['sku']) || !method_exists($data['sku'], 'getId')) {
            $this->logger->warning('SKU类型错误或缺少getId方法，跳过SKU限制检查', [
                'sku' => gettype($data['sku']),
                'hasGetIdMethod' => is_object($data['sku']) ? method_exists($data['sku'], 'getId') : false,
            ]);

            return;
        }

        $skuId = $data['sku']->getId();
        $skuIdStr = match (true) {
            is_string($skuId) => $skuId,
            is_int($skuId) => (string) $skuId,
            is_float($skuId) => (string) $skuId,
            default => 'unknown',
        };
        $skuLimitRules = $this->limitRuleService->findSkuLimitRulesBySkuId($skuIdStr);
        foreach ($skuLimitRules as $limitRule) {
            $this->skuLimitChecker->checkSkuLimitRule($limitRule, $orderProduct);
        }
    }

    /**
     * 根据目录配置，检查用户是否可以购买指定SKU
     * 简化后只需要委托给专门的检查器
     */
    public function checkCategory(OrderProduct $orderProduct): void
    {
        if (null === $this->limitRuleService) {
            return;
        }

        $data = $this->dataExtractor->extractOrderProductData($orderProduct);
        if (null === $data) {
            return;
        }

        $spu = $this->dataExtractor->extractSpuFromSku($data['sku']);
        if (null === $spu) {
            return;
        }

        $categories = $this->dataExtractor->getSpuCategories($spu);
        if ([] === $categories) {
            $this->logger->warning('无法获取SPU分类', ['spu' => $spu]);

            return;
        }

        foreach ($categories as $category) {
            $this->processCategoryLimitRules($category, $orderProduct);
        }
    }

    /**
     * 处理单个分类的限制规则
     * 提取类型检查逻辑降低认知复杂度
     */
    private function processCategoryLimitRules(mixed $category, OrderProduct $orderProduct): void
    {
        // 检查 limitRuleService 是否可用
        if (null === $this->limitRuleService) {
            $this->logger->warning('LimitRuleService 不可用，跳过分类限制检查');

            return;
        }

        // 类型安全检查：确保 $category 有 getId 方法
        if (!is_object($category) || !method_exists($category, 'getId')) {
            $this->logger->warning('分类类型错误或缺少getId方法，跳过该分类的限制检查', [
                'category' => gettype($category),
                'hasGetIdMethod' => is_object($category) ? method_exists($category, 'getId') : false,
            ]);

            return;
        }

        $categoryId = $category->getId();
        $categoryIdStr = match (true) {
            is_string($categoryId) => $categoryId,
            is_int($categoryId) => (string) $categoryId,
            is_float($categoryId) => (string) $categoryId,
            default => 'unknown',
        };
        $categoryLimitRules = $this->limitRuleService->findCategoryLimitRulesByCategoryId($categoryIdStr);
        foreach ($categoryLimitRules as $limitRule) {
            $this->categoryLimitChecker->checkCategoryLimitRule($limitRule, $orderProduct, $category);
        }
    }
}
