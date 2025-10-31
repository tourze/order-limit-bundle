<?php

namespace OrderLimitBundle\Limit;

use Carbon\CarbonInterface;
use Monolog\Attribute\WithMonologChannel;
use OrderCoreBundle\Entity\Contract;
use OrderCoreBundle\Entity\OrderProduct;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Security\Core\User\UserInterface;
use Tourze\ProductCoreBundle\Entity\Sku;
use Tourze\ProductLimitRuleBundle\Entity\SkuLimitRule;
use Tourze\ProductLimitRuleBundle\Enum\SkuLimitType;
use Tourze\ProductLimitRuleBundle\Exception\LimitRuleTriggerException;

/**
 * SKU限制检查器 - 专门处理SKU级别的购买限制
 * 使用"好品味"原则：消除特殊情况，减少嵌套
 */
#[WithMonologChannel(channel: 'order_limit')]
#[Autoconfigure(public: true)]
readonly class SkuLimitChecker
{
    public function __construct(
        private LoggerInterface $logger,
        private QueryBuilder $queryBuilder,
        private TimeRangeCalculator $timeRangeCalculator,
        private LimitValidator $limitValidator,
        private DataExtractor $dataExtractor,
    ) {
    }

    /**
     * 检查SKU限制规则
     * 使用卫语句消除嵌套判断
     */
    public function checkSkuLimitRule(SkuLimitRule $limitRule, OrderProduct $orderProduct): void
    {
        $data = $this->dataExtractor->extractOrderProductData($orderProduct);
        if (null === $data) {
            return;
        }

        if (!$this->isRuleValid($limitRule, $data['sku'])) {
            return;
        }

        $this->logRuleStart($limitRule, $data);
        $this->executeSkuLimitCheck($limitRule, $orderProduct, $data);
        $this->logRulePass($limitRule, $data);
    }

    /**
     * 验证规则是否有效
     */
    private function isRuleValid(SkuLimitRule $limitRule, mixed $sku): bool
    {
        $value = $this->dataExtractor->safeGetValue($limitRule);
        if ('' === $value || null === $value) {
            $this->logger->warning('SKU规则没配置值，跳过', [
                'sku' => $sku,
                'rule' => $limitRule,
            ]);

            return false;
        }

        return true;
    }

    /**
     * 执行SKU限制检查
     * 使用match替代复杂的if/else
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int} $data
     */
    private function executeSkuLimitCheck(SkuLimitRule $limitRule, OrderProduct $orderProduct, array $data): void
    {
        $type = $this->dataExtractor->safeGetType($limitRule);
        if (null === $type) {
            return;
        }

        match ($type) {
            SkuLimitType::MIN_QUANTITY => $this->limitValidator->checkMinQuantity($limitRule, $orderProduct),
            SkuLimitType::SPECIFY_COUPON => $this->limitValidator->checkCouponLimit($limitRule, $data['contract']),
            SkuLimitType::SKU_MUTEX => $this->checkSkuMutexLimit($limitRule, $data['contract']),
            SkuLimitType::BUY_TOTAL => $this->checkSkuTotalLimit($limitRule, $data),
            SkuLimitType::BUY_YEAR, SkuLimitType::BUY_QUARTER, SkuLimitType::BUY_MONTH, SkuLimitType::BUY_DAILY => $this->checkSkuTimeBasedLimit($limitRule, $data),
            default => null,
        };
    }

    /**
     * 检查SKU总数限制
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int} $data
     */
    private function checkSkuTotalLimit(SkuLimitRule $limitRule, array $data): void
    {
        $count = $this->getUserSkuTotalCount($data['user'], $data['sku']);
        $limit = $this->dataExtractor->getLimitRuleValue($this->dataExtractor->safeGetValue($limitRule));

        if ($count > $limit) {
            $this->throwLimitExceeded($limit, $count, $data['quantity'], $limitRule);
        }

        if (($count + $data['quantity']) > $limit) {
            $rest = $limit - $count;
            $this->limitValidator->handleRestLimitError($rest, $count, $this->createOrderProductMock($data), $limitRule, 'SKU');
        }
    }

    /**
     * 检查SKU时间范围限制
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int} $data
     */
    private function checkSkuTimeBasedLimit(SkuLimitRule $limitRule, array $data): void
    {
        $timeRange = $this->timeRangeCalculator->getTimeRange($limitRule);
        $this->logger->debug('SKU规则计算开始和结束时间', [
            'startTime' => $timeRange['start'],
            'endTime' => $timeRange['end'],
        ]);

        $count = $this->getUserSkuTimeBasedCount($data['user'], $data['sku'], $timeRange['start'], $timeRange['end']);
        $limit = $this->dataExtractor->getLimitRuleValue($this->dataExtractor->safeGetValue($limitRule));

        if ($count > $limit) {
            $this->throwLimitExceeded($limit, $count, $data['quantity'], $limitRule);
        }

        if (($count + $data['quantity']) > $limit) {
            $rest = $limit - $count;
            $this->limitValidator->handleRestLimitError($rest, $count, $this->createOrderProductMock($data), $limitRule, 'SKU');
        }
    }

    /**
     * 检查SKU互斥限制
     */
    private function checkSkuMutexLimit(SkuLimitRule $limitRule, Contract $contract): void
    {
        $mutexSkuValue = $this->dataExtractor->safeGetValue($limitRule) ?? '';
        $mutexSku = $this->dataExtractor->findSkuByIdOrGtin($mutexSkuValue);

        if (null === $mutexSku) {
            $this->logger->warning('检查商品互斥配置时发现SKU不存在', [
                'rule' => $limitRule,
                'value' => $mutexSkuValue,
            ]);

            return;
        }

        $this->checkSkuMutexInCurrentOrder($contract, $limitRule, $mutexSku);
        $this->checkSkuMutexInHistory($contract, $mutexSku);
    }

    /**
     * 检查当前订单中的SKU互斥
     */
    private function checkSkuMutexInCurrentOrder(Contract $contract, SkuLimitRule $limitRule, mixed $mutexSku): void
    {
        $ruleSkuId = $limitRule->getSkuId();
        $mutexSkuId = intval($this->dataExtractor->safeGetValue($limitRule));

        foreach ($contract->getProducts() as $product) {
            $currentSku = $product->getSku();
            if (null === $currentSku) {
                continue;
            }

            $currentSkuId = $currentSku->getId();
            if ($currentSkuId === (int) $ruleSkuId) {
                continue;
            }

            if ((string) $currentSkuId === (string) $mutexSkuId) {
                throw new LimitRuleTriggerException('SKU_MUTEX_CURRENT_ORDER', $limitRule->getId() ?? 'unknown', (string) $mutexSkuId, (string) $currentSkuId, '您不符合购买资格');
            }
        }
    }

    /**
     * 检查历史购买中的SKU互斥
     */
    private function checkSkuMutexInHistory(Contract $contract, mixed $mutexSku): void
    {
        if (!$this->isSku($mutexSku)) {
            return;
        }

        // 再次检查 mutexSku 是否是对象且有 getId 方法
        if (!is_object($mutexSku) || !method_exists($mutexSku, 'getId')) {
            $this->logger->warning('mutexSku 对象无效或缺少getId方法', [
                'mutexSku' => gettype($mutexSku),
                'isObject' => is_object($mutexSku),
                'hasGetIdMethod' => is_object($mutexSku) ? method_exists($mutexSku, 'getId') : false,
            ]);

            return;
        }

        $count = $this->getUserSkuPurchaseCount($contract->getUser(), $mutexSku);
        if ($count > 0) {
            $mutexSkuId = $mutexSku->getId();
            $mutexSkuIdStr = match (true) {
                is_string($mutexSkuId) => $mutexSkuId,
                is_int($mutexSkuId) => (string) $mutexSkuId,
                is_float($mutexSkuId) => (string) $mutexSkuId,
                default => 'unknown',
            };
            throw new LimitRuleTriggerException('SKU_MUTEX_HISTORY', $mutexSkuIdStr, '0', (string) $count, '您不符合购买资格');
        }
    }

    /**
     * 获取用户SKU总购买数量
     */
    private function getUserSkuTotalCount(mixed $user, mixed $sku): int
    {
        if (!$this->isUserInterface($user) || !$this->isSku($sku)) {
            return 0;
        }

        // 类型安全检查：确保是正确的类型
        if (!$user instanceof UserInterface || !$sku instanceof Sku) {
            return 0;
        }

        $orderSQL = $this->queryBuilder->buildUserOrdersQuery($user);

        return $this->queryBuilder->executeSkuCountQuery($orderSQL, $user, $sku, []);
    }

    /**
     * 获取用户SKU时间范围内购买数量
     */
    private function getUserSkuTimeBasedCount(mixed $user, mixed $sku, CarbonInterface $startTime, CarbonInterface $endTime): int
    {
        if (!$this->isUserInterface($user) || !$this->isSku($sku)) {
            return 0;
        }

        // 类型安全检查：确保是正确的类型
        if (!$user instanceof UserInterface || !$sku instanceof Sku) {
            return 0;
        }

        $orderSQL = $this->queryBuilder->buildUserOrdersWithTimeQuery($user, $startTime, $endTime);

        return $this->queryBuilder->executeSkuCountQuery($orderSQL, $user, $sku, [
            'orderStartTime' => $startTime->format('Y-m-d H:i:s'),
            'orderEndTime' => $endTime->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 获取用户SKU历史购买数量
     */
    private function getUserSkuPurchaseCount(mixed $user, mixed $sku): int
    {
        return $this->getUserSkuTotalCount($user, $sku);
    }

    /**
     * 抛出超限异常
     */
    private function throwLimitExceeded(int $limit, int $count, int $quantity, SkuLimitRule $limitRule): void
    {
        $this->logger->warning("最多只能购买{$limit}件", [
            'count' => $count,
            'quantity' => $quantity,
            'limitRule' => $limitRule,
        ]);

        $message = $_ENV['SKU_BUY_LIMIT_ALERT_MSG'] ?? "最多只能购买{$limit}件";
        // 确保 $message 是字符串类型
        $message = is_string($message) ? $message : "最多只能购买{$limit}件";
        throw new LimitRuleTriggerException('SKU_LIMIT', (string) $limitRule->getId(), (string) $limit, (string) $count, $message);
    }

    /**
     * 创建OrderProduct模拟对象用于错误处理
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int} $data
     */
    private function createOrderProductMock(array $data): OrderProduct
    {
        $orderProduct = new OrderProduct();
        $orderProduct->setQuantity($data['quantity']);

        return $orderProduct;
    }

    /**
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int} $data
     */
    private function logRuleStart(SkuLimitRule $limitRule, array $data): void
    {
        $this->logger->debug('开始检查SKU规则', [
            'sku' => $data['sku'],
            'rule' => $limitRule,
            'type' => $this->dataExtractor->safeGetType($limitRule)->value ?? '',
            'quantity' => $data['quantity'],
        ]);
    }

    /**
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int} $data
     */
    private function logRulePass(SkuLimitRule $limitRule, array $data): void
    {
        $this->logger->debug('SKU规则检查通过', [
            'sku' => $data['sku'],
            'rule' => $limitRule,
            'type' => $this->dataExtractor->safeGetType($limitRule)->value ?? '',
            'quantity' => $data['quantity'],
        ]);
    }

    /**
     * 检查是否为有效的 UserInterface 实例
     */
    private function isUserInterface(mixed $user): bool
    {
        return $user instanceof UserInterface;
    }

    /**
     * 检查是否为有效的 Sku 实例
     */
    private function isSku(mixed $sku): bool
    {
        return $sku instanceof Sku;
    }
}
