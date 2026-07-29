<?php

namespace Tests\Unit;

use App\Services\CommercialPriceRounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CommercialPriceRoundingTest extends TestCase
{
    #[DataProvider('euroPrices')]
    public function test_euro_commercial_prices_follow_the_brand_rule(float $raw, float $expected): void
    {
        $result = CommercialPriceRounding::apply($raw, null, null, 'EUR', CommercialPriceRounding::MODE_COMMERCIAL);

        $this->assertSame($expected, $result['after']);
    }

    public static function euroPrices(): array
    {
        return [
            'nearest 5 below 100' => [74, 75.0],
            'tie below 100 goes up' => [77, 79.0],
            'second tie below 100 goes up' => [82, 85.0],
            'nearest 9 below 100' => [98, 99.0],
            'strictly below 100 stays on a 5/9 ending' => [99.8, 99.0],
            '100 remains 100' => [100, 100.0],
            'nearest step 5 above 100' => [102, 100.0],
            'tie above 100 goes up' => [102.5, 105.0],
        ];
    }

    public function test_all_uses_hundred_lek_steps(): void
    {
        $this->assertSame(1200.0, CommercialPriceRounding::apply(1249, null, null, 'ALL', 'commercial')['after']);
        $this->assertSame(1300.0, CommercialPriceRounding::apply(1250, null, null, 'ALL', 'commercial')['after']);
    }

    public function test_rounding_never_crosses_owner_guardrails(): void
    {
        $this->assertSame(115.0, CommercialPriceRounding::apply(110, 113, null, 'EUR', 'commercial')['after']);
        $this->assertSame(110.0, CommercialPriceRounding::apply(130, null, 113, 'EUR', 'commercial')['after']);
        $this->assertSame(113.0, CommercialPriceRounding::apply(130, 113, 113, 'EUR', 'commercial')['after']);
    }

    public function test_rounding_never_reverses_the_calculated_direction(): void
    {
        $increase = CommercialPriceRounding::apply(102.10, null, null, 'EUR', 'commercial', 102);
        $decrease = CommercialPriceRounding::apply(102.90, null, null, 'EUR', 'commercial', 103);
        $neutral = CommercialPriceRounding::apply(102, null, null, 'EUR', 'commercial', 102);

        $this->assertSame(105.0, $increase['after']);
        $this->assertSame(100.0, $decrease['after']);
        $this->assertSame(102.0, $neutral['after']);
    }

    public function test_exact_mode_preserves_cents_after_guardrails(): void
    {
        $result = CommercialPriceRounding::apply(88.83, 80, 90, 'EUR', CommercialPriceRounding::MODE_EXACT);

        $this->assertSame(88.83, $result['after']);
        $this->assertFalse($result['applied']);
    }
}
