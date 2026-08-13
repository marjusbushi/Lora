<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A human name must actually contain letters — the desk once registered a
 * guest named "!" to skip the field, which poisons reports and the legal
 * guest registry. Unicode-aware: ë, ç, Cyrillic, Hebrew, Arabic all count.
 * Machine imports (Channex/OTA) bypass form validation on purpose — this
 * rule guards human entry points only.
 */
class ContainsLetters implements ValidationRule
{
    public function __construct(private readonly int $min = 2) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (preg_match_all('/\p{L}/u', (string) $value) < $this->min) {
            $fail(app()->getLocale() === 'sq'
                ? "Emri duhet të përmbajë të paktën {$this->min} shkronja."
                : "The name must contain at least {$this->min} letters.");
        }
    }
}
