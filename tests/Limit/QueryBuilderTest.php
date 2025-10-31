<?php

namespace OrderLimitBundle\Tests\Limit;

use Carbon\CarbonImmutable;
use OrderLimitBundle\Limit\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Security\Core\User\UserInterface;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\ProductCoreBundle\Entity\Sku;
use Tourze\ProductCoreBundle\Entity\Spu;

/**
 * @internal
 */
#[CoversClass(QueryBuilder::class)]
#[RunTestsInSeparateProcesses]
final class QueryBuilderTest extends AbstractIntegrationTestCase
{
    private QueryBuilder $queryBuilder;

    protected function onSetUp(): void
    {
        // 不需要调用 parent::onSetUp()，因为 AbstractIntegrationTestCase 的 onSetUp() 是抽象方法

        $this->queryBuilder = self::getService(QueryBuilder::class);
    }

    public function testServiceExists(): void
    {
        $this->assertInstanceOf(QueryBuilder::class, $this->queryBuilder);
    }

    public function testBuildUserOrdersQuery(): void
    {
        $user = $this->createMock(UserInterface::class);

        $dql = $this->queryBuilder->buildUserOrdersQuery($user);

        $this->assertIsString($dql);
        $this->assertStringContainsString('o.user = :user', $dql);
        $this->assertStringContainsString('o.state NOT IN (:excludeState)', $dql);
    }

    public function testBuildUserOrdersWithTimeQuery(): void
    {
        $user = $this->createMock(UserInterface::class);
        $startTime = CarbonImmutable::now()->subDays(7);
        $endTime = CarbonImmutable::now();

        $dql = $this->queryBuilder->buildUserOrdersWithTimeQuery($user, $startTime, $endTime);

        $this->assertIsString($dql);
        $this->assertStringContainsString('o.createTime BETWEEN', $dql);
    }

    public function testExecuteSpuCountQuery(): void
    {
        // 创建真实用户对象而不是Mock，因为Doctrine需要序列化这些对象
        $user = $this->createNormalUser('test@example.com', 'password');

        // 为Spu创建具有getId方法的Mock对象
        $spu = $this->createMock(Spu::class);
        $spu->method('getId')->willReturn(1);

        $orderSQL = 'SELECT o.id FROM OrderCoreBundle\Entity\Contract o WHERE o.user = :user';

        $count = $this->queryBuilder->executeSpuCountQuery($orderSQL, $user, $spu, []);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testExecuteSkuCountQuery(): void
    {
        // 创建真实用户对象而不是Mock，因为Doctrine需要序列化这些对象
        $user = $this->createNormalUser('sku-test@example.com', 'password');

        // 为Sku创建具有getId方法的Mock对象
        $sku = $this->createMock(Sku::class);
        $sku->method('getId')->willReturn('1');

        $orderSQL = 'SELECT o.id FROM OrderCoreBundle\Entity\Contract o WHERE o.user = :user';

        $count = $this->queryBuilder->executeSkuCountQuery($orderSQL, $user, $sku, []);

        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }
}
