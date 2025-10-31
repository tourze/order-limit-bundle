<?php

namespace OrderLimitBundle\Limit;

use Carbon\CarbonInterface;
use Doctrine\ORM\NoResultException;
use OrderCoreBundle\Enum\OrderState;
use OrderCoreBundle\Repository\ContractRepository;
use OrderCoreBundle\Repository\OrderProductRepository;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Security\Core\User\UserInterface;
use Tourze\ProductCoreBundle\Entity\Sku;
use Tourze\ProductCoreBundle\Entity\Spu;

#[Autoconfigure(public: true)]
class QueryBuilder
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly OrderProductRepository $orderProductRepository,
    ) {
    }

    public function buildUserOrdersQuery(UserInterface $user, ?object $store = null): string
    {
        $qb = $this->contractRepository->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.user = :user AND o.state NOT IN (:excludeState)')
        ;

        return $qb->getDQL();
    }

    public function buildUserOrdersWithTimeQuery(UserInterface $user, CarbonInterface $startTime, CarbonInterface $endTime): string
    {
        return $this->contractRepository->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.user = :user AND o.state NOT IN (:excludeState) AND o.createTime BETWEEN :orderStartTime AND :orderEndTime')
            ->getDQL()
        ;
    }

    /**
     * @param array<string, mixed> $extraParams
     */
    public function executeSpuCountQuery(string $orderSQL, UserInterface $user, Spu $spu, array $extraParams): int
    {
        try {
            $query = $this->orderProductRepository->createQueryBuilder('a')
                ->select('SUM(a.quantity)')
                ->where("a.contract IN ({$orderSQL}) AND a.spu = :spu")
                ->setParameter('spu', $spu)
                ->setParameter('user', $user)
            ;

            // 只有当orderSQL中包含excludeState参数时才绑定
            if (false !== strpos($orderSQL, ':excludeState')) {
                $query->setParameter('excludeState', [OrderState::CANCELED]);
            }

            // 只有在Contract有store字段且store不为null时才设置store参数
            foreach ($extraParams as $key => $value) {
                $query->setParameter($key, $value);
            }

            $count = $query->getQuery()->getSingleScalarResult();

            return intval($count);
        } catch (NoResultException) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $extraParams
     */
    public function executeSkuCountQuery(string $orderSQL, UserInterface $user, Sku $sku, array $extraParams): int
    {
        try {
            $query = $this->orderProductRepository->createQueryBuilder('a')
                ->select('SUM(a.quantity)')
                ->where("a.contract IN ({$orderSQL}) AND a.sku = :sku")
                ->setParameter('sku', $sku)
                ->setParameter('user', $user)
            ;

            // 只有当orderSQL中包含excludeState参数时才绑定
            if (false !== strpos($orderSQL, ':excludeState')) {
                $query->setParameter('excludeState', [OrderState::CANCELED]);
            }

            foreach ($extraParams as $key => $value) {
                $query->setParameter($key, $value);
            }

            $count = $query->getQuery()->getSingleScalarResult();

            return intval($count);
        } catch (NoResultException) {
            return 0;
        }
    }
}
