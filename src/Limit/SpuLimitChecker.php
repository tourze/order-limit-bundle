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
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Security\Core\User\UserInterface;
use Tourze\ProductCoreBundle\Entity\Spu;
use Tourze\ProductCoreBundle\Service\SpuService;
use Tourze\ProductLimitRuleBundle\Entity\SpuLimitRule;
use Tourze\ProductLimitRuleBundle\Enum\SpuLimitType;
use Tourze\ProductLimitRuleBundle\Exception\LimitRuleTriggerException;

/**
 * SPU限制检查器 - 专门处理SPU级别的购买限制
 * 使用"好品味"原则：消除特殊情况，减少嵌套
 */
#[WithMonologChannel(channel: 'order_limit')]
#[Autoconfigure(public: true)]
readonly class SpuLimitChecker
{
    public function __construct(
        private LoggerInterface $logger,
        private QueryBuilder $queryBuilder,
        private TimeRangeCalculator $timeRangeCalculator,
        private LimitValidator $limitValidator,
        private DataExtractor $dataExtractor,
        private SpuService $spuService,
        private ContractRepository $contractRepository,
        private OrderProductRepository $orderProductRepository,
    ) {
    }

    /**
     * 检查SPU限制规则
     * 使用卫语句消除嵌套判断
     */
    public function checkSpuLimitRule(SpuLimitRule $limitRule, OrderProduct $orderProduct): void
    {
        $data = $this->dataExtractor->extractOrderProductData($orderProduct);
        if (null === $data) {
            return;
        }

        $spu = $this->dataExtractor->extractSpuFromSku($data['sku']);
        if (null === $spu) {
            return;
        }

        if (!$this->isRuleValid($limitRule, $data['sku'])) {
            return;
        }

        $spuQuantity = $this->dataExtractor->calculateSpuQuantityInContract($data['contract'], $spu);
        $data['spu'] = $spu;
        $data['spuQuantity'] = $spuQuantity;

        $this->logRuleStart($limitRule, $data);
        $this->executeSpuLimitCheck($limitRule, $orderProduct, $data);
        $this->logRulePass($limitRule, $data);
    }

    /**
     * 验证规则是否有效
     */
    private function isRuleValid(SpuLimitRule $limitRule, mixed $sku): bool
    {
        $value = $this->dataExtractor->safeGetValue($limitRule);
        if (null === $value || '' === $value) {
            $this->logger->warning('SPU规则没配置值，跳过', [
                'rule' => $limitRule,
            ]);

            return false;
        }

        return true;
    }

    /**
     * 执行SPU限制检查
     * 使用match替代复杂的if/else
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int, spu: mixed, spuQuantity: int} $data
     */
    private function executeSpuLimitCheck(SpuLimitRule $limitRule, OrderProduct $orderProduct, array $data): void
    {
        $type = $this->dataExtractor->safeGetType($limitRule);
        if (null === $type) {
            return;
        }

        match ($type) {
            SpuLimitType::SPECIFY_COUPON => $this->limitValidator->checkCouponLimit($limitRule, $data['contract']),
            SpuLimitType::SPU_MUTEX => $this->checkSpuMutexLimit($limitRule, $data['contract']),
            SpuLimitType::BUY_TOTAL => $this->checkSpuTotalLimit($limitRule, $data),
            default => $this->checkSpuTimeBasedLimit($limitRule, $data),
        };
    }

    /**
     * 检查SPU总数限制
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int, spu: mixed, spuQuantity: int} $data
     */
    private function checkSpuTotalLimit(SpuLimitRule $limitRule, array $data): void
    {
        $count = $this->getUserSpuTotalCount($data['user'], $data['spu']);
        $this->limitValidator->validateLimit($count, $data['spuQuantity'], $limitRule, $this->createOrderProductMock($data), 'SPU');
    }

    /**
     * 检查SPU时间范围限制
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int, spu: mixed, spuQuantity: int} $data
     */
    private function checkSpuTimeBasedLimit(SpuLimitRule $limitRule, array $data): void
    {
        $timeBasedTypes = [
            SpuLimitType::BUY_YEAR, SpuLimitType::BUY_QUARTER, SpuLimitType::BUY_MONTH, SpuLimitType::BUY_DAILY,
        ];

        $type = $this->dataExtractor->safeGetType($limitRule);
        if (!in_array($type, $timeBasedTypes, true)) {
            return;
        }

        $timeRange = $this->timeRangeCalculator->getTimeRange($limitRule);
        $this->logger->debug('SPU规则计算开始和结束时间', [
            'startTime' => $timeRange['start'],
            'endTime' => $timeRange['end'],
        ]);

        $count = $this->getUserSpuTimeBasedCount(
            $data['user'],
            $data['spu'],
            $timeRange['start'],
            $timeRange['end'],
            $type
        );

        $this->limitValidator->validateLimit($count, $data['spuQuantity'], $limitRule, $this->createOrderProductMock($data), 'SPU');
    }

    /**
     * 检查SPU互斥限制
     */
    private function checkSpuMutexLimit(SpuLimitRule $limitRule, Contract $contract): void
    {
        $mutexSpuValue = $this->dataExtractor->safeGetValue($limitRule);
        if (null === $mutexSpuValue) {
            return;
        }
        $mutexSpu = $this->spuService->findValidSpuByIdOrGtin($mutexSpuValue);

        if (null === $mutexSpu) {
            $this->logger->warning('检查商品互斥配置时发现SPU不存在', [
                'rule' => $limitRule,
                'value' => $mutexSpuValue,
            ]);

            return;
        }

        $count = $this->getUserSpuPurchaseCount($contract->getUser(), $mutexSpu);
        if ($count > 0) {
            throw new LimitRuleTriggerException('SPU_MUTEX', (string) $mutexSpu->getId(), '0', (string) $count, '您不符合购买资格');
        }
    }

    /**
     * 获取用户SPU总购买数量
     */
    private function getUserSpuTotalCount(mixed $user, mixed $spu): int
    {
        // 类型安全检查：确保 $user 是 UserInterface 实例
        if (!$user instanceof UserInterface) {
            $this->logger->warning('用户类型错误，跳过SPU总数限制检查', [
                'user' => gettype($user),
                'expected' => UserInterface::class,
            ]);

            return 0;
        }

        // 类型安全检查：确保 $spu 是 Spu 实例
        if (!$spu instanceof Spu) {
            $this->logger->warning('SPU类型错误，跳过SPU总数限制检查', [
                'spu' => gettype($spu),
                'expected' => Spu::class,
            ]);

            return 0;
        }

        $orderSQL = $this->queryBuilder->buildUserOrdersQuery($user);

        return $this->queryBuilder->executeSpuCountQuery($orderSQL, $user, $spu, []);
    }

    /**
     * 获取用户SPU时间范围内购买数量
     */
    private function getUserSpuTimeBasedCount(mixed $user, mixed $spu, CarbonInterface $startTime, CarbonInterface $endTime, SpuLimitType $limitType): int
    {
        // 类型安全检查：确保 $user 是 UserInterface 实例
        if (!$user instanceof UserInterface) {
            $this->logger->warning('用户类型错误，跳过SPU时间范围限制检查', [
                'user' => gettype($user),
                'expected' => UserInterface::class,
            ]);

            return 0;
        }

        // 类型安全检查：确保 $spu 是 Spu 实例
        if (!$spu instanceof Spu) {
            $this->logger->warning('SPU类型错误，跳过SPU时间范围限制检查', [
                'spu' => gettype($spu),
                'expected' => Spu::class,
            ]);

            return 0;
        }

        $qb = $this->contractRepository->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.user = :user AND o.state NOT IN (:excludeState) AND o.createTime BETWEEN :orderStartTime AND :orderEndTime')
        ;

        $orderSQL = $qb->getDQL();

        try {
            $query = $this->orderProductRepository->createQueryBuilder('a')
                ->select('SUM(a.quantity)')
                ->where("a.contract IN ({$orderSQL}) AND a.spu = :spu")
                ->setParameter('spu', $spu)
                ->setParameter('user', $user)
                ->setParameter('excludeState', [OrderState::CANCELED])
                ->setParameter('orderStartTime', $startTime->format('Y-m-d H:i:s'))
                ->setParameter('orderEndTime', $endTime->format('Y-m-d H:i:s'))
            ;

            $count = $query->getQuery()->getSingleScalarResult();

            return intval($count);
        } catch (NoResultException) {
            return 0;
        }
    }

    /**
     * 获取用户SPU历史购买数量
     */
    private function getUserSpuPurchaseCount(mixed $user, mixed $spu): int
    {
        // 类型安全检查：确保 $user 是 UserInterface 实例
        if (!$user instanceof UserInterface) {
            $this->logger->warning('用户类型错误，跳过SPU历史购买数量检查', [
                'user' => gettype($user),
                'expected' => UserInterface::class,
            ]);

            return 0;
        }

        // 类型安全检查：确保 $spu 是 Spu 实例
        if (!$spu instanceof Spu) {
            $this->logger->warning('SPU类型错误，跳过SPU历史购买数量检查', [
                'spu' => gettype($spu),
                'expected' => Spu::class,
            ]);

            return 0;
        }

        $orderSQL = $this->contractRepository->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.user = :user AND o.state NOT IN (:excludeState)')
            ->getDQL()
        ;

        try {
            $count = $this->orderProductRepository->createQueryBuilder('a')
                ->select('SUM(a.quantity)')
                ->where("a.contract IN ({$orderSQL}) AND a.spu = :spu")
                ->setParameter('user', $user)
                ->setParameter('spu', $spu)
                ->setParameter('excludeState', [OrderState::CANCELED])
                ->getQuery()
                ->getSingleScalarResult()
            ;

            return intval($count);
        } catch (NoResultException) {
            return 0;
        }
    }

    /**
     * 创建OrderProduct模拟对象用于错误处理
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int, spu: mixed, spuQuantity: int} $data
     */
    private function createOrderProductMock(array $data): OrderProduct
    {
        $orderProduct = new OrderProduct();
        $orderProduct->setQuantity($data['spuQuantity']);

        return $orderProduct;
    }

    /**
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int, spu: mixed, spuQuantity: int} $data
     */
    private function logRuleStart(SpuLimitRule $limitRule, array $data): void
    {
        $this->logger->debug('开始检查SPU规则', [
            'sku' => $data['sku'],
            'rule' => $limitRule,
            'type' => $this->dataExtractor->safeGetType($limitRule)->value ?? '',
            'quantity' => $data['quantity'],
        ]);
    }

    /**
     * @param array{contract: Contract, user: mixed, sku: mixed, quantity: int, spu: mixed, spuQuantity: int} $data
     */
    private function logRulePass(SpuLimitRule $limitRule, array $data): void
    {
        $this->logger->debug('SPU规则检查通过', [
            'sku' => $data['sku'],
            'rule' => $limitRule,
            'type' => $this->dataExtractor->safeGetType($limitRule)->value ?? '',
            'quantity' => $data['quantity'],
        ]);
    }
}
