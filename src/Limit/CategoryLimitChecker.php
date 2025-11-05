<?php

namespace OrderLimitBundle\Limit;

use Carbon\CarbonInterface;
use Doctrine\ORM\NoResultException;
use Monolog\Attribute\WithMonologChannel;
use OrderCoreBundle\Entity\Contract;
use OrderCoreBundle\Entity\OrderProduct;
use OrderCoreBundle\Enum\OrderState;
use OrderCoreBundle\Repository\ContractRepository;
use OrderCoreBundle\Repository\OrderProductRepository;
use OrderLimitBundle\Exception\ContractNotFoundException;
use OrderLimitBundle\Exception\LimitRuleTriggerException;
use OrderLimitBundle\Exception\SkuNotFoundException;
use OrderLimitBundle\Exception\UserNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Tourze\ProductLimitRuleBundle\Entity\CategoryLimitRule;
use Tourze\ProductLimitRuleBundle\Enum\CategoryLimitType;

/**
 * 分类限制检查器 - 专门处理分类级别的购买限制
 * 使用"好品味"原则：消除特殊情况，减少嵌套
 */
#[WithMonologChannel(channel: 'order_limit')]
#[Autoconfigure(public: true)]
readonly class CategoryLimitChecker
{
    public function __construct(
        private LoggerInterface $logger,
        private TimeRangeCalculator $timeRangeCalculator,
        private LimitValidator $limitValidator,
        private DataExtractor $dataExtractor,
        private ContractRepository $contractRepository,
        private OrderProductRepository $orderProductRepository,
    ) {
    }

    /**
     * 检查分类限制规则
     * 使用卫语句消除嵌套判断
     */
    public function checkCategoryLimitRule(CategoryLimitRule $limitRule, OrderProduct $orderProduct, mixed $category): void
    {
        $data = $this->extractAndValidateData($orderProduct);
        $spuQuantity = $this->dataExtractor->calculateCategoryQuantityInOrder($data['contract'], $category);

        $categoryData = array_merge($data, [
            'category' => $category,
            'spuQuantity' => $spuQuantity,
        ]);

        if (!$this->isRuleValid($limitRule, $categoryData)) {
            return;
        }

        $this->logRuleStart($limitRule, $categoryData);
        $this->executeCategoryLimitCheck($limitRule, $categoryData);
        $this->logRulePass($limitRule, $categoryData);
    }

    /**
     * 提取并验证订单产品数据
     * @return array{contract: Contract, user: mixed, sku: mixed, quantity: int}
     */
    private function extractAndValidateData(OrderProduct $orderProduct): array
    {
        $contract = $orderProduct->getContract();
        if (null === $contract) {
            throw new ContractNotFoundException('Contract not found');
        }

        $user = $contract->getUser();
        if (null === $user) {
            throw new UserNotFoundException('User not found');
        }

        $sku = $orderProduct->getSku();
        if (null === $sku) {
            throw new SkuNotFoundException('SKU not found');
        }

        return [
            'contract' => $contract,
            'user' => $user,
            'sku' => $sku,
            'quantity' => $orderProduct->getQuantity(),
        ];
    }

    /**
     * 验证规则是否有效
     * @param array{contract: mixed, user: mixed, sku: mixed, quantity: int, category: mixed, spuQuantity: int} $data
     */
    private function isRuleValid(CategoryLimitRule $limitRule, array $data): bool
    {
        $value = $this->dataExtractor->safeGetValue($limitRule);
        if (null === $value || '' === $value) {
            $this->logger->warning('分类规则没配置值，跳过', [
                'sku' => $data['sku'],
                'rule' => $limitRule,
                'category' => $data['category'],
                'quantity' => $data['quantity'],
            ]);

            return false;
        }

        return true;
    }

    /**
     * 执行分类限制检查
     * 使用match替代复杂的if/else
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int, category: mixed, spuQuantity: int} $data
     */
    private function executeCategoryLimitCheck(CategoryLimitRule $limitRule, array $data): void
    {
        $type = $this->dataExtractor->safeGetType($limitRule);
        if (null === $type) {
            return;
        }

        // 确保 contract 是 Contract 类型
        $contract = $data['contract'];
        if (!$contract instanceof Contract) {
            $this->logger->warning('Contract 类型错误，跳过分类限制检查', [
                'contractType' => gettype($contract),
                'limitRule' => $limitRule,
            ]);

            return;
        }

        match ($type) {
            CategoryLimitType::SPECIFY_COUPON => $this->limitValidator->checkCouponLimit($limitRule, $contract),
            CategoryLimitType::BUY_TOTAL => $this->checkCategoryTotalLimit($limitRule, $data),
            CategoryLimitType::BUY_YEAR, CategoryLimitType::BUY_QUARTER, CategoryLimitType::BUY_MONTH, CategoryLimitType::BUY_DAILY => $this->checkCategoryTimeBasedLimit($limitRule, $data),
            default => null,
        };
    }

    /**
     * 检查分类总数限制
     * @param array{contract: mixed, user: mixed, sku: mixed, quantity: int, category: mixed, spuQuantity: int} $data
     */
    private function checkCategoryTotalLimit(CategoryLimitRule $limitRule, array $data): void
    {
        $count = $this->getUserCategoryTotalCount($data['user'], $data['category']);
        $limit = $this->dataExtractor->getLimitRuleValue($this->dataExtractor->safeGetValue($limitRule));

        if ($count > $limit) {
            $this->throwLimitExceeded($limit, $count, $data['quantity'], $limitRule);
        }

        if (($count + $data['spuQuantity']) > $limit) {
            $rest = $limit - $count;
            $this->throwRestLimitExceeded($rest);
        }
    }

    /**
     * 检查分类时间范围限制
     * @param array{contract: mixed, user: mixed, sku: mixed, quantity: int, category: mixed, spuQuantity: int} $data
     */
    private function checkCategoryTimeBasedLimit(CategoryLimitRule $limitRule, array $data): void
    {
        $timeRange = $this->timeRangeCalculator->getTimeRange($limitRule);
        $this->logger->debug('分类规则计算开始和结束时间', [
            'startTime' => $timeRange['start'],
            'endTime' => $timeRange['end'],
        ]);

        $count = $this->getUserCategoryTimeBasedCount($data['user'], $data['category'], $timeRange['start'], $timeRange['end']);
        $limit = $this->dataExtractor->getLimitRuleValue($this->dataExtractor->safeGetValue($limitRule));

        if ($count > $limit) {
            $this->throwLimitExceeded($limit, $count, $data['quantity'], $limitRule);
        }

        if (($count + $data['spuQuantity']) > $limit) {
            $rest = $limit - $count;
            $this->throwRestLimitExceeded($rest);
        }
    }

    /**
     * 获取用户分类总购买数量
     */
    private function getUserCategoryTotalCount(mixed $user, mixed $category): int
    {
        // 检查 category 是否有 getId 方法
        if (!is_object($category) || !method_exists($category, 'getId')) {
            $this->logger->warning('分类对象无效或缺少getId方法', [
                'category' => gettype($category),
                'hasGetIdMethod' => is_object($category) ? method_exists($category, 'getId') : false,
            ]);

            return 0;
        }

        $spuSQL = $this->dataExtractor->getSpuIdsByCategoryDQL();
        $orderSQL = $this->contractRepository->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.user = :user AND o.state NOT IN (:excludeState)')
            ->getDQL()
        ;

        try {
            $count = $this->orderProductRepository->createQueryBuilder('a')
                ->select('SUM(a.quantity)')
                ->where("a.contract IN ({$orderSQL}) AND a.spu IN ({$spuSQL})")
                ->setParameter('categoryId', $category->getId())
                ->setParameter('user', $user)
                ->setParameter('excludeState', [OrderState::CANCELED])
                ->getQuery()
                ->getSingleScalarResult()
            ;
        } catch (NoResultException) {
            $count = 0;
        }

        return intval($count);
    }

    /**
     * 获取用户分类时间范围内购买数量
     */
    private function getUserCategoryTimeBasedCount(mixed $user, mixed $category, CarbonInterface $startTime, CarbonInterface $endTime): int
    {
        // 检查 category 是否有 getId 方法
        if (!is_object($category) || !method_exists($category, 'getId')) {
            $this->logger->warning('分类对象无效或缺少getId方法', [
                'category' => gettype($category),
                'hasGetIdMethod' => is_object($category) ? method_exists($category, 'getId') : false,
            ]);

            return 0;
        }

        $spuSQL = $this->dataExtractor->getSpuIdsByCategoryDQL();
        $orderSQL = $this->contractRepository->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.user = :user AND o.state NOT IN (:excludeState) AND o.createTime BETWEEN :orderStartTime AND :orderEndTime')
            ->getDQL()
        ;

        try {
            $count = $this->orderProductRepository->createQueryBuilder('a')
                ->select('SUM(a.quantity)')
                ->where("a.contract IN ({$orderSQL}) AND a.spu IN ({$spuSQL})")
                ->setParameter('categoryId', $category->getId())
                ->setParameter('user', $user)
                ->setParameter('excludeState', [OrderState::CANCELED])
                ->setParameter('orderStartTime', $startTime->format('Y-m-d H:i:s'))
                ->setParameter('orderEndTime', $endTime->format('Y-m-d H:i:s'))
                ->getQuery()
                ->getSingleScalarResult()
            ;
        } catch (NoResultException) {
            $count = 0;
        }

        return intval($count);
    }

    /**
     * 抛出超限异常
     */
    private function throwLimitExceeded(int $limit, int $count, int $quantity, CategoryLimitRule $limitRule): void
    {
        $this->logger->warning("最多只能购买{$limit}件", [
            'count' => $count,
            'quantity' => $quantity,
            'limitRule' => $limitRule,
        ]);

        $envMessage = $_ENV['CATEGORY_BUY_LIMIT_ALERT_MSG'] ?? null;
        $message = is_string($envMessage) ? $envMessage : "最多只能购买{$limit}件";
        throw new LimitRuleTriggerException('CATEGORY_LIMIT', (string) $limitRule->getId(), (string) $limit, (string) $count, $message);
    }

    /**
     * 抛出剩余数量限制异常
     */
    private function throwRestLimitExceeded(int $rest): void
    {
        $message = $rest > 0 ? "只能继续购买{$rest}件" : $this->getMaxBuyMessage();
        throw new LimitRuleTriggerException('CATEGORY_REST_LIMIT', 'category_rest', $rest > 0 ? (string) $rest : '0', '', $message);
    }

    /**
     * 获取最大购买限制信息
     */
    private function getMaxBuyMessage(): string
    {
        $envMessage = $_ENV['MAX_BUY_LIMIT_MSG'] ?? null;

        return is_string($envMessage) ? $envMessage : '已达到购买上限';
    }

    /**
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int, category: mixed, spuQuantity: int} $data
     */
    private function logRuleStart(CategoryLimitRule $limitRule, array $data): void
    {
        $this->logger->debug('开始检查目录规则', [
            'sku' => $data['sku'],
            'rule' => $limitRule,
            'category' => $data['category'],
            'quantity' => $data['quantity'],
            'spuQuantity' => $data['spuQuantity'],
        ]);
    }

    /**
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int, category: mixed, spuQuantity: int} $data
     */
    private function logRulePass(CategoryLimitRule $limitRule, array $data): void
    {
        $this->logger->debug('分类规则检查通过', [
            'sku' => $data['sku'],
            'rule' => $limitRule,
            'type' => $this->dataExtractor->safeGetType($limitRule)->value ?? '',
            'quantity' => $data['quantity'],
            'spuQuantity' => $data['spuQuantity'],
        ]);
    }
}
