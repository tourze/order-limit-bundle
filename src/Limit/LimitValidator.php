<?php

namespace OrderLimitBundle\Limit;

use Monolog\Attribute\WithMonologChannel;
use OrderCoreBundle\Entity\Contract;
use OrderCoreBundle\Entity\OrderProduct;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Tourze\ProductLimitRuleBundle\Entity\CategoryLimitRule;
use Tourze\ProductLimitRuleBundle\Entity\SkuLimitRule;
use Tourze\ProductLimitRuleBundle\Entity\SpuLimitRule;
use Tourze\ProductLimitRuleBundle\Exception\LimitRuleTriggerException;

#[WithMonologChannel(channel: 'order_limit')]
#[Autoconfigure(public: true)]
readonly class LimitValidator
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function checkCouponLimit(SpuLimitRule|CategoryLimitRule|SkuLimitRule $limitRule, Contract $contract): void
    {
        $value = $limitRule->getValue();
        if (null === $value) {
            return;
        }
        $requiredCouponIds = array_map('trim', explode(',', $value));

        // 优惠券功能已废弃，跳过优惠券验证
    }

    /**
     * 检查最小购买数量限制
     *
     * 不考虑并发
     */
    public function checkMinQuantity(SkuLimitRule $limitRule, OrderProduct $orderProduct): void
    {
        $minQuantity = intval($limitRule->getValue());
        if ($orderProduct->getQuantity() < $minQuantity) {
            $this->logger->warning("最少需要购买{$minQuantity}件", [
                'quantity' => $orderProduct->getQuantity(),
                'limitRule' => $limitRule,
                'orderProductId' => $orderProduct->getId(),
            ]);
            throw new LimitRuleTriggerException('MIN_QUANTITY_LIMIT', (string) $limitRule->getId(), (string) $minQuantity, (string) $orderProduct->getQuantity(), "最少需要购买{$minQuantity}件");
        }
    }

    public function validateLimit(int $count, int $newQuantity, SpuLimitRule|SkuLimitRule $limitRule, OrderProduct $orderProduct, string $type): void
    {
        $limit = intval($limitRule->getValue());

        if ($count > $limit) {
            $this->logger->warning("最多只能购买{$limit}件", [
                'count' => $count,
                'quantity' => $newQuantity,
                'limitRule' => $limitRule,
                'type' => $type,
            ]);
            $envMessage = $_ENV["{$type}_BUY_LIMIT_ALERT_MSG"] ?? null;
            $message = is_string($envMessage) ? $envMessage : "最多只能购买{$limit}件";
            throw new LimitRuleTriggerException($type . '_LIMIT', (string) $limitRule->getId(), (string) $limit, (string) $count, $message);
        }

        if (($count + $newQuantity) > $limit) {
            $rest = $limit - $count;
            $this->handleRestLimitError($rest, $count, $orderProduct, $limitRule, $type);
        }
    }

    public function handleRestLimitError(int $rest, int $count, OrderProduct $orderProduct, SpuLimitRule|SkuLimitRule $limitRule, string $type): void
    {
        $this->logger->warning("只能继续购买{$rest}件", [
            'rest' => $rest,
            'count' => $count,
            'quantity' => $orderProduct->getQuantity(),
            'limitRule' => $limitRule,
            'type' => $type,
        ]);

        $message = $rest > 0 ? "只能继续购买{$rest}件" : $this->getMaxBuyMessage();
        throw new LimitRuleTriggerException($type . '_REST_LIMIT', (string) $limitRule->getId(), $rest > 0 ? (string) $rest : '0', (string) $count, $message);
    }

    private function getMaxBuyMessage(): string
    {
        $envMessage = $_ENV['MAX_BUY_LIMIT_MSG'] ?? null;

        return is_string($envMessage) ? $envMessage : '已达到购买上限';
    }
}
