<?php

declare(strict_types=1);

namespace App\Services\Reports\Insight;

/**
 * What one rule found: a sentence (Rich markup) and, when useful, one concrete thing to check or do.
 *
 * $weight says how important the sentence is when the insight has to be shortened (higher stays). An essential finding is never
 * dropped. $actionPriority orders the actions for the "Priority:" line (1 is most useful).
 */
final class Finding
{
    public function __construct(
        public readonly string $text,
        public readonly int $weight = 50,
        public readonly ?string $action = null,
        public readonly int $actionPriority = 5,
        public readonly bool $essential = false,
        public readonly string $rule = '',
    ) {
    }

    public function withRule(string $rule): self
    {
        return new self($this->text, $this->weight, $this->action, $this->actionPriority, $this->essential, $rule);
    }
}
