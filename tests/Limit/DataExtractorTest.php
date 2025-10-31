<?php

namespace OrderLimitBundle\Tests\Limit;

use Doctrine\Common\Collections\ArrayCollection;
use OrderCoreBundle\Entity\Contract;
use OrderCoreBundle\Entity\OrderProduct;
use OrderLimitBundle\Limit\DataExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Security\Core\User\UserInterface;
use Tourze\PHPUnitSymfonyKernelTest\AbstractIntegrationTestCase;
use Tourze\ProductCoreBundle\DTO\ProductCategory;
use Tourze\ProductCoreBundle\Entity\Sku;
use Tourze\ProductCoreBundle\Entity\Spu;
use Tourze\ProductLimitRuleBundle\Enum\CategoryLimitType;

/**
 * DataExtractor集成测试 - 验证数据提取器的各种安全调用场景
 *
 * 测试重点：
 * 1. 安全的方法调用（safeGetValue, safeGetType）
 * 2. OrderProduct数据提取的各种边界情况
 * 3. SPU和分类数量计算逻辑
 * 4. 反射调用的异常处理
 * 5. null值处理和边界条件
 *
 * @internal
 */
#[CoversClass(DataExtractor::class)]
#[RunTestsInSeparateProcesses]
final class DataExtractorTest extends AbstractIntegrationTestCase
{
    private DataExtractor $dataExtractor;

    protected function onSetUp(): void
    {
        $this->dataExtractor = self::getService(DataExtractor::class);
    }

    public function testServiceExistsShouldReturnValidInstance(): void
    {
        $this->assertInstanceOf(DataExtractor::class, $this->dataExtractor);
    }

    public function testGetLimitRuleValueWithValidStringShouldReturnInteger(): void
    {
        $result = $this->dataExtractor->getLimitRuleValue('123');

        $this->assertSame(123, $result);
    }

    public function testGetLimitRuleValueWithNullShouldReturnZero(): void
    {
        $result = $this->dataExtractor->getLimitRuleValue(null);

        $this->assertSame(0, $result);
    }

    public function testGetLimitRuleValueWithEmptyStringShouldReturnZero(): void
    {
        $result = $this->dataExtractor->getLimitRuleValue('');

        $this->assertSame(0, $result);
    }

    public function testGetLimitRuleValueWithInvalidStringShouldReturnZero(): void
    {
        $result = $this->dataExtractor->getLimitRuleValue('invalid');

        $this->assertSame(0, $result);
    }

    public function testSafeGetValueWithObjectHavingGetValueMethodShouldReturnValue(): void
    {
        $mockObject = $this->createMockWithGetValueMethod('test-value');

        $result = $this->dataExtractor->safeGetValue($mockObject);

        $this->assertSame('test-value', $result);
    }

    public function testSafeGetValueWithObjectNotHavingGetValueMethodShouldReturnNull(): void
    {
        $mockObject = new \stdClass();

        $result = $this->dataExtractor->safeGetValue($mockObject);

        $this->assertNull($result);
    }

    public function testSafeGetTypeWithObjectHavingGetTypeMethodShouldReturnType(): void
    {
        $expectedType = CategoryLimitType::BUY_TOTAL;
        $mockObject = $this->createMockWithGetTypeMethod($expectedType);

        $result = $this->dataExtractor->safeGetType($mockObject);

        $this->assertSame($expectedType, $result);
    }

    public function testSafeGetTypeWithObjectNotHavingGetTypeMethodShouldReturnNull(): void
    {
        $mockObject = new \stdClass();

        $result = $this->dataExtractor->safeGetType($mockObject);

        $this->assertNull($result);
    }

    public function testExtractOrderProductDataWithValidDataShouldReturnCompleteArray(): void
    {
        $mockUser = $this->createMock(UserInterface::class);
        $mockSku = $this->createMockSku();
        $mockContract = $this->createMockContract();
        $mockContract->method('getUser')->willReturn($mockUser);

        $orderProduct = $this->createMockOrderProduct(5);
        $orderProduct->method('getContract')->willReturn($mockContract);
        $orderProduct->method('getSku')->willReturn($mockSku);

        $result = $this->dataExtractor->extractOrderProductData($orderProduct);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('contract', $result);
        $this->assertArrayHasKey('user', $result);
        $this->assertArrayHasKey('sku', $result);
        $this->assertArrayHasKey('quantity', $result);
        $this->assertSame($mockContract, $result['contract']);
        $this->assertSame($mockUser, $result['user']);
        $this->assertSame($mockSku, $result['sku']);
        $this->assertSame(5, $result['quantity']);
    }

    public function testExtractOrderProductDataWithNullContractShouldReturnNull(): void
    {
        $orderProduct = $this->createMockOrderProduct(5);
        $orderProduct->method('getContract')->willReturn(null);

        $result = $this->dataExtractor->extractOrderProductData($orderProduct);

        $this->assertNull($result);
    }

    public function testExtractOrderProductDataWithNullUserShouldReturnNull(): void
    {
        $mockContract = $this->createMockContract();
        $mockContract->method('getUser')->willReturn(null);

        $orderProduct = $this->createMockOrderProduct(5);
        $orderProduct->method('getContract')->willReturn($mockContract);

        $result = $this->dataExtractor->extractOrderProductData($orderProduct);

        $this->assertNull($result);
    }

    public function testExtractOrderProductDataWithNullSkuShouldReturnNull(): void
    {
        $mockUser = $this->createMock(UserInterface::class);
        $mockContract = $this->createMockContract();
        $mockContract->method('getUser')->willReturn($mockUser);

        $orderProduct = $this->createMockOrderProduct(5);
        $orderProduct->method('getContract')->willReturn($mockContract);
        $orderProduct->method('getSku')->willReturn(null);

        $result = $this->dataExtractor->extractOrderProductData($orderProduct);

        $this->assertNull($result);
    }

    public function testExtractSpuFromSkuWithValidSkuShouldReturnSpu(): void
    {
        $mockSpu = $this->createMockSpu();
        $mockSku = $this->createMockSku();
        $mockSku->method('getSpu')->willReturn($mockSpu);

        $result = $this->dataExtractor->extractSpuFromSku($mockSku);

        $this->assertSame($mockSpu, $result);
    }

    public function testExtractSpuFromSkuWithNullSkuShouldReturnNull(): void
    {
        $result = $this->dataExtractor->extractSpuFromSku(null);

        $this->assertNull($result);
    }

    public function testCalculateSpuQuantityInContractWithNullSpuShouldReturnZero(): void
    {
        $mockContract = $this->createMockContract();

        $result = $this->dataExtractor->calculateSpuQuantityInContract($mockContract, null);

        $this->assertSame(0, $result);
    }

    public function testCalculateSpuQuantityInContractWithMatchingSpuShouldReturnCorrectTotal(): void
    {
        $targetSpu = $this->createMockSpu(100);
        $otherSpu = $this->createMockSpu(200);

        // 创建多个订单产品，部分匹配目标SPU
        $product1 = $this->createMockOrderProduct(3);
        $product1->method('getSpu')->willReturn($targetSpu);

        $product2 = $this->createMockOrderProduct(5);
        $product2->method('getSpu')->willReturn($otherSpu); // 不匹配

        $product3 = $this->createMockOrderProduct(7);
        $product3->method('getSpu')->willReturn($targetSpu); // 匹配

        $mockContract = $this->createMockContract();
        $mockContract->method('getProducts')->willReturn(new ArrayCollection([$product1, $product2, $product3]));

        $result = $this->dataExtractor->calculateSpuQuantityInContract($mockContract, $targetSpu);

        $this->assertSame(10, $result); // 3 + 7 = 10
    }

    public function testCalculateSpuQuantityInContractWithNullProductSpuShouldSkipProduct(): void
    {
        $targetSpu = $this->createMockSpu(100);

        $product1 = $this->createMockOrderProduct(3);
        $product1->method('getSpu')->willReturn($targetSpu);

        $product2 = $this->createMockOrderProduct(5);
        $product2->method('getSpu')->willReturn(null); // null SPU应该被跳过

        $mockContract = $this->createMockContract();
        $mockContract->method('getProducts')->willReturn(new ArrayCollection([$product1, $product2]));

        $result = $this->dataExtractor->calculateSpuQuantityInContract($mockContract, $targetSpu);

        $this->assertSame(3, $result);
    }

    public function testCalculateCategoryQuantityInOrderWithMatchingCategoryShouldReturnCorrectTotal(): void
    {
        $targetCategory = $this->createMockCategory(1);
        $otherCategory = $this->createMockCategory(2);

        // 创建具有不同分类的SPU
        $spu1 = $this->createMockSpuWithCategories([$targetCategory, $otherCategory]);
        $spu2 = $this->createMockSpuWithCategories([$otherCategory]); // 不包含目标分类
        $spu3 = $this->createMockSpuWithCategories([$targetCategory]); // 包含目标分类

        $product1 = $this->createMockOrderProduct(4);
        $product1->method('getSpu')->willReturn($spu1);

        $product2 = $this->createMockOrderProduct(6);
        $product2->method('getSpu')->willReturn($spu2);

        $product3 = $this->createMockOrderProduct(8);
        $product3->method('getSpu')->willReturn($spu3);

        $mockContract = $this->createMockContract();
        $mockContract->method('getProducts')->willReturn(new ArrayCollection([$product1, $product2, $product3]));

        $result = $this->dataExtractor->calculateCategoryQuantityInOrder($mockContract, $targetCategory);

        $this->assertSame(12, $result); // 4 + 8 = 12 (product2不匹配)
    }

    public function testCalculateCategoryQuantityInOrderWithNullProductSpuShouldSkipProduct(): void
    {
        $targetCategory = $this->createMockCategory(1);

        $product1 = $this->createMockOrderProduct(5);
        $product1->method('getSpu')->willReturn(null);

        $mockContract = $this->createMockContract();
        $mockContract->method('getProducts')->willReturn(new ArrayCollection([$product1]));

        $result = $this->dataExtractor->calculateCategoryQuantityInOrder($mockContract, $targetCategory);

        $this->assertSame(0, $result);
    }

    public function testGetSpuCategoriesWithValidSpuShouldReturnCategories(): void
    {
        $categories = [$this->createMockCategory(1), $this->createMockCategory(2)];
        $spu = $this->createMockSpuWithCategories($categories);

        $result = $this->dataExtractor->getSpuCategories($spu);

        $this->assertSame($categories, $result);
    }

    public function testGetSpuCategoriesWithInvalidObjectShouldReturnEmptyArray(): void
    {
        $invalidObject = new \stdClass();

        $result = $this->dataExtractor->getSpuCategories($invalidObject);

        $this->assertSame([], $result);
    }

    public function testFindSkuByIdOrGtinWithValidSkuServiceShouldCallMethod(): void
    {
        $this->expectNotToPerformAssertions();

        // 主要目的是验证反射调用能正常执行而不抛出异常
        $this->dataExtractor->findSkuByIdOrGtin('TEST-SKU-001');

        // 能执行到这里就说明方法调用成功，没有抛出异常
    }

    public function testGetSpuIdsByCategoryDQLShouldReturnDQLString(): void
    {
        $result = $this->dataExtractor->getSpuIdsByCategoryDQL();

        // 验证返回字符串类型，具体DQL内容由SpuService决定
        $this->assertIsString($result);
    }

    /**
     * 创建具有getValue方法的模拟对象
     */
    private function createMockWithGetValueMethod(string $value): object
    {
        $mock = new class {
            private string $value;

            public function setValue(string $value): void
            {
                $this->value = $value;
            }

            public function getValue(): string
            {
                return $this->value;
            }
        };

        $mock->setValue($value);

        return $mock;
    }

    /**
     * 创建具有getType方法的模拟对象
     */
    private function createMockWithGetTypeMethod(CategoryLimitType $type): object
    {
        $mock = new class {
            private CategoryLimitType $type;

            public function setType(CategoryLimitType $type): void
            {
                $this->type = $type;
            }

            public function getType(): CategoryLimitType
            {
                return $this->type;
            }
        };

        $mock->setType($type);

        return $mock;
    }

    /**
     * 创建模拟的OrderProduct
     */
    private function createMockOrderProduct(int $quantity): OrderProduct
    {
        $orderProduct = $this->createMock(OrderProduct::class);
        $orderProduct->method('getQuantity')->willReturn($quantity);

        return $orderProduct;
    }

    /**
     * 创建模拟的Contract
     */
    private function createMockContract(): Contract
    {
        return $this->createMock(Contract::class);
    }

    /**
     * 创建模拟的SKU
     */
    private function createMockSku(): object
    {
        return $this->createMock(Sku::class);
    }

    /**
     * 创建模拟的SPU
     */
    private function createMockSpu(int $id = 1): object
    {
        $spu = $this->createMock(Spu::class);
        $spu->method('getId')->willReturn($id);

        return $spu;
    }

    /**
     * 创建模拟的Category
     */
    private function createMockCategory(int $id): ProductCategory
    {
        return new ProductCategory(
            id: $id,
            name: "Category {$id}",
            valid: true
        );
    }

    /**
     * 创建具有指定分类的模拟SPU
     * @param array<mixed> $categories
     */
    private function createMockSpuWithCategories(array $categories): object
    {
        $spu = $this->createMock(Spu::class);

        // 使用反射模拟getCategories方法的行为
        $spu->method('getCategories')->willReturn(new ArrayCollection($categories));

        return $spu;
    }
}
