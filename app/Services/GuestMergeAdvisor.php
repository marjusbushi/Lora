<?php

namespace App\Services;

use App\Models\Guest;

class GuestMergeAdvisor
{
    /** @return array{primary_id:int, reason_key:string, source:string, metrics:array<string,mixed>} */
    public function advise(Guest $first, Guest $second): array
    {
        $metrics = [
            'A' => $this->metrics($first),
            'B' => $this->metrics($second),
        ];
        $fallback = $this->fallbackAdvice($first, $second, $metrics);
        $client = app(GeminiClient::class);

        if (! $client->configured()) {
            return $fallback;
        }

        try {
            $result = $client->structured(
                'You advise hotel staff which of two duplicate guest profiles should remain primary. Use only the anonymized operational metrics. Prefer the profile with more reservation history, documents, invoices, reviews, completeness, then the older profile. Return one allowed reason key.',
                'Choose the primary profile from these anonymized metrics: '.json_encode($metrics, JSON_THROW_ON_ERROR),
                $this->tool(),
                'suggest_guest_merge',
                512,
                20,
            );

            $alias = in_array($result['primary'] ?? null, ['A', 'B'], true) ? $result['primary'] : null;
            $reason = in_array($result['reason_key'] ?? null, ['more_history', 'more_complete', 'older_profile', 'balanced'], true)
                ? $result['reason_key']
                : null;

            if ($alias && $reason) {
                return [
                    'primary_id' => $alias === 'A' ? $first->id : $second->id,
                    'reason_key' => $reason,
                    'source' => 'ai',
                    'metrics' => $metrics,
                ];
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $fallback;
    }

    /** @return array<string,int|string> */
    private function metrics(Guest $guest): array
    {
        $complete = collect([
            $guest->first_name,
            $guest->last_name,
            $guest->email,
            $guest->phone,
            $guest->nationality,
            $guest->date_of_birth,
            $guest->document_number,
        ])->filter(fn ($value) => filled($value))->count();

        return [
            'completeness' => $complete,
            'reservations' => $guest->reservations()->withTrashed()->count(),
            'documents' => $guest->documents()->count(),
            'invoices' => $guest->invoices()->count(),
            'reviews' => $guest->reviews()->count(),
            'created_at' => $guest->created_at?->toDateString() ?? '',
        ];
    }

    /** @return array{primary_id:int, reason_key:string, source:string, metrics:array<string,mixed>} */
    public function fallbackAdvice(Guest $first, Guest $second, ?array $metrics = null): array
    {
        $metrics ??= [
            'A' => $this->metrics($first),
            'B' => $this->metrics($second),
        ];
        $score = fn (array $profile): int => ($profile['reservations'] * 5)
            + ($profile['documents'] * 4)
            + ($profile['invoices'] * 3)
            + ($profile['reviews'] * 2)
            + $profile['completeness'];
        $scoreA = $score($metrics['A']);
        $scoreB = $score($metrics['B']);

        $primary = $scoreA === $scoreB
            ? ($first->created_at <= $second->created_at ? $first : $second)
            : ($scoreA > $scoreB ? $first : $second);

        $historyA = $metrics['A']['reservations'] + $metrics['A']['documents'] + $metrics['A']['invoices'] + $metrics['A']['reviews'];
        $historyB = $metrics['B']['reservations'] + $metrics['B']['documents'] + $metrics['B']['invoices'] + $metrics['B']['reviews'];
        $reason = $historyA !== $historyB
            ? 'more_history'
            : ($metrics['A']['completeness'] !== $metrics['B']['completeness'] ? 'more_complete' : 'older_profile');

        return [
            'primary_id' => $primary->id,
            'reason_key' => $reason,
            'source' => 'fallback',
            'metrics' => $metrics,
        ];
    }

    /** @return array<string,mixed> */
    private function tool(): array
    {
        return [
            'name' => 'suggest_guest_merge',
            'description' => 'Choose which anonymized duplicate guest profile should remain primary.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'primary' => ['type' => 'string', 'enum' => ['A', 'B']],
                    'reason_key' => ['type' => 'string', 'enum' => ['more_history', 'more_complete', 'older_profile', 'balanced']],
                ],
                'required' => ['primary', 'reason_key'],
            ],
        ];
    }
}
