<?php

namespace OrderLimitBundle\Tests\Limit;

use Carbon\CarbonImmutable;
use OrderLimitBundle\Limit\TimeRangeCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\ProductLimitRuleBundle\Entity\SpuLimitRule;
use Tourze\ProductLimitRuleBundle\Enum\SpuLimitType;

/**
 * @internal
 */
#[CoversClass(TimeRangeCalculator::class)]
#[RunTestsInSeparateProcesses]
final class TimeRangeCalculatorTest extends AbstractIntegrationTestCase
{
    private TimeRangeCalculator $timeRangeCalculator;

    protected function onSetUp(): void
    {
        // 不需要调用 parent::onSetUp()，因为 AbstractIntegrationTestCase 的 onSetUp() 是抽象方法

        $this->timeRangeCalculator = self::getService(TimeRangeCalculator::class);
    }

    public function testServiceExists(): void
    {
        $this->assertInstanceOf(TimeRangeCalculator::class, $this->timeRangeCalculator);
    }

    public function testGetTimeRangeWithDailyLimit(): void
    {
        $limitRule = new SpuLimitRule();
        $limitRule->setType(SpuLimitType::BUY_DAILY);
        $limitRule->setValue('5');

        $result = $this->timeRangeCalculator->getTimeRange($limitRule);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('start', $result);
        $this->assertArrayHasKey('end', $result);
    }

    public function testGetTimeRangeWithMonthlyLimit(): void
    {
        $limitRule = new SpuLimitRule();
        $limitRule->setType(SpuLimitType::BUY_MONTH);
        $limitRule->setValue('10');

        $result = $this->timeRangeCalculator->getTimeRange($limitRule);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('start', $result);
        $this->assertArrayHasKey('end', $result);
    }

    public function testGetTimeRangeWithYearlyLimit(): void
    {
        $limitRule = new SpuLimitRule();
        $limitRule->setType(SpuLimitType::BUY_YEAR);
        $limitRule->setValue('100');

        $result = $this->timeRangeCalculator->getTimeRange($limitRule);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('start', $result);
        $this->assertArrayHasKey('end', $result);
        $this->assertCount(2, $result);
    }

    public function testGetTimeRangeReturnsValidCarbonInstances(): void
    {
        $limitRule = new SpuLimitRule();
        $limitRule->setType(SpuLimitType::BUY_QUARTER);
        $limitRule->setValue('50');

        $result = $this->timeRangeCalculator->getTimeRange($limitRule);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('start', $result);
        $this->assertArrayHasKey('end', $result);
        $this->assertInstanceOf(CarbonImmutable::class, $result['start']);
        $this->assertInstanceOf(CarbonImmutable::class, $result['end']);
    }
}
