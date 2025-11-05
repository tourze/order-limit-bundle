<?php

declare(strict_types=1);

namespace OrderLimitBundle\Exception;

/**
 * 限购规则触发异常
 *
 * 当用户触发限购规则时抛出此异常，包含详细的规则信息用于调试和用户提示
 */
class LimitRuleTriggerException extends \Exception
{
    public function __construct(
        private readonly string $ruleType,
        private readonly string $entityId,
        private readonly string $limitValue,
        private readonly string $currentValue = '',
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        $effectiveMessage = '' !== $message ? $message : $this->generateDefaultMessage();
        parent::__construct($effectiveMessage, $code, $previous);
    }

    public function getRuleType(): string
    {
        return $this->ruleType;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getLimitValue(): string
    {
        return $this->limitValue;
    }

    public function getCurrentValue(): string
    {
        return $this->currentValue;
    }

    private function generateDefaultMessage(): string
    {
        $message = "限购规则触发: {$this->ruleType} 规则限制实体 {$this->entityId}，限制值: {$this->limitValue}";

        if ('' !== $this->currentValue) {
            $message .= "，当前值: {$this->currentValue}";
        }

        return $message;
    }
}
