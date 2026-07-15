<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Validation\ValidationException;

class VatConfiguration
{
    public const REGISTERED = 'registered';

    public const NOT_REGISTERED = 'not_registered';

    public const ACCOMMODATION_RATE = 6;

    public const PRODUCT_RATE = 20;

    private const ALLOWED_RATES = [0, 6, 10, 20];

    public function status(): ?string
    {
        $status = Setting::get('financial.vat_status');

        return in_array($status, [self::REGISTERED, self::NOT_REGISTERED], true)
            ? $status
            : null;
    }

    public function configured(): bool
    {
        return $this->status() !== null;
    }

    public function registered(): bool
    {
        return $this->status() === self::REGISTERED;
    }

    public function accommodationRate(): int
    {
        return $this->registered() ? self::ACCOMMODATION_RATE : 0;
    }

    public function productRate(): int
    {
        return $this->registered() ? self::PRODUCT_RATE : 0;
    }

    public function folioRate(float|int|string|null $storedRate): int
    {
        if (! $this->registered()) {
            return 0;
        }

        if ($storedRate === null || $storedRate === '') {
            return $this->productRate();
        }

        $rate = (float) $storedRate;
        if (abs($rate - (int) $rate) > 0.0001 || ! in_array((int) $rate, self::ALLOWED_RATES, true)) {
            throw ValidationException::withMessages([
                'fiscalization' => 'Një rresht i faturës ka normë TVSH-je të pambështetur.',
            ]);
        }

        return (int) $rate;
    }

    public function taxPortion(float $gross, int $rate): float
    {
        if ($gross <= 0 || $rate <= 0) {
            return 0.0;
        }

        return round($gross - ($gross / (1 + $rate / 100)), 2);
    }

    public function ensureConfigured(): void
    {
        if ($this->configured()) {
            return;
        }

        throw ValidationException::withMessages([
            'fiscalization' => 'Zgjidh fillimisht në Settings → Financa nëse hoteli është me apo pa TVSH.',
        ]);
    }
}
