<?php

namespace OrderLimitBundle\Limit;

use Carbon\CarbonImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Tourze\ProductLimitRuleBundle\Entity\CategoryLimitRule;
use Tourze\ProductLimitRuleBundle\Entity\SkuLimitRule;
use Tourze\ProductLimitRuleBundle\Entity\SpuLimitRule;
use Tourze\ProductLimitRuleBundle\Enum\CategoryLimitType;
use Tourze\ProductLimitRuleBundle\Enum\SkuLimitType;
use Tourze\ProductLimitRuleBundle\Enum\SpuLimitType;

#[Autoconfigure(public: true)]
class TimeRangeCalculator
{
    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function getTimeRange(CategoryLimitRule|SpuLimitRule|SkuLimitRule $limitRule): array
    {
        $now = CarbonImmutable::now();
        $startTime = null;
        $endTime = null;

        // 根据限购规则类型计算时间范围
        $type = $limitRule->getType();

        switch (true) {
            case $type instanceof SpuLimitType:
                [$startTime, $endTime] = $this->calculateSpuTimeRange($type, $now);
                break;
            case $type instanceof SkuLimitType:
                [$startTime, $endTime] = $this->calculateSkuTimeRange($type, $now);
                break;
            default:
                [$startTime, $endTime] = $this->calculateCategoryTimeRange($type, $now);
                break;
        }

        return [
            'start' => $startTime ?? $now->copy()->startOfDay(),
            'end' => $endTime ?? $now->copy()->endOfDay(),
        ];
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function calculateSpuTimeRange(SpuLimitType $type, CarbonImmutable $now): array
    {
        switch ($type) {
            case SpuLimitType::BUY_DAILY:
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
            case SpuLimitType::BUY_MONTH:
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
            case SpuLimitType::BUY_QUARTER:
                return [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()];
            case SpuLimitType::BUY_YEAR:
                return [$now->copy()->startOfYear(), $now->copy()->endOfYear()];
            default:
                return [null, null];
        }
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function calculateSkuTimeRange(SkuLimitType $type, CarbonImmutable $now): array
    {
        switch ($type) {
            case SkuLimitType::BUY_DAILY:
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
            case SkuLimitType::BUY_MONTH:
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
            case SkuLimitType::BUY_QUARTER:
                return [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()];
            case SkuLimitType::BUY_YEAR:
                return [$now->copy()->startOfYear(), $now->copy()->endOfYear()];
            default:
                return [null, null];
        }
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function calculateCategoryTimeRange(CategoryLimitType $type, CarbonImmutable $now): array
    {
        switch ($type) {
            case CategoryLimitType::BUY_DAILY:
                return [$now->copy()->startOfDay(), $now->copy()->endOfDay()];
            case CategoryLimitType::BUY_MONTH:
                return [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()];
            case CategoryLimitType::BUY_QUARTER:
                return [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()];
            case CategoryLimitType::BUY_YEAR:
                return [$now->copy()->startOfYear(), $now->copy()->endOfYear()];
            default:
                return [null, null];
        }
    }
}
