<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportingPeriod
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(string|CarbonImmutable $from, string|CarbonImmutable $to)
    {
        $this->from = $from instanceof CarbonImmutable ? $from->startOfDay() : CarbonImmutable::parse($from)->startOfDay();
        $this->to = $to instanceof CarbonImmutable ? $to->startOfDay() : CarbonImmutable::parse($to)->startOfDay();

        if ($this->from->greaterThan($this->to)) {
            throw new InvalidArgumentException('Reporting period start must be before or equal to its end.');
        }
    }

    public function days(): int
    {
        return $this->from->diffInDays($this->to) + 1;
    }

    public function previousPeriod(): self
    {
        $to = $this->from->subDay();

        return new self($to->subDays($this->days() - 1), $to);
    }

    public function previousYear(): self
    {
        return new self($this->from->subYearNoOverflow(), $this->to->subYearNoOverflow());
    }

    public function contains(string|CarbonImmutable $date): bool
    {
        $value = $date instanceof CarbonImmutable ? $date->startOfDay() : CarbonImmutable::parse($date)->startOfDay();

        return $value->betweenIncluded($this->from, $this->to);
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
        ];
    }
}
