<?php

namespace App\Services;

use App\Jobs\PushRoomTypeAri;
use App\Models\AiActionProposal;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\MessageThread;
use App\Models\RateOverride;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AiActionExecutor
{
    private const MIN_BAND = 0.25;

    private const MAX_BAND = 4.0;

    public function execute(AiActionProposal $proposal, User $user): array
    {
        if ((int) $proposal->user_id !== (int) $user->id) {
            throw new RuntimeException('This proposal belongs to another user.');
        }
        if ($proposal->status === 'executed') {
            return ['state' => 'already_executed', 'executed_at' => $proposal->executed_at?->toIso8601String()];
        }
        if ($proposal->status !== 'pending' || $proposal->expires_at->isPast()) {
            throw new RuntimeException('This proposal has expired or is no longer pending.');
        }

        return match ($proposal->type) {
            'guest_reply' => $this->sendGuestReply($proposal),
            'pricing_range' => $this->applyPricingRange($proposal),
            default => throw new RuntimeException('Unsupported proposal type.'),
        };
    }

    private function sendGuestReply(AiActionProposal $proposal): array
    {
        $claimed = AiActionProposal::query()->whereKey($proposal->id)->where('status', 'pending')
            ->where('expires_at', '>', now())->update(['status' => 'executing']);
        if ($claimed !== 1) {
            throw new RuntimeException('This proposal is already being executed.');
        }

        $payload = $proposal->payload;
        $thread = MessageThread::findOrFail($payload['thread_id']);
        if (! $thread->channex_thread_id) {
            $proposal->update(['status' => 'failed']);
            throw new RuntimeException('This conversation has no active channel connection.');
        }

        try {
            app(ChannexClient::class)->sendThreadMessage($thread->channex_thread_id, $payload['body']);
        } catch (\Throwable $exception) {
            $proposal->update(['status' => 'pending']);
            report($exception);
            throw new RuntimeException('The guest reply could not be sent. The proposal remains pending; try again later.');
        }

        $thread->messages()->create([
            'channex_message_id' => null,
            'sender' => Message::SENDER_HOST,
            'body' => $payload['body'],
            'sent_at' => now(),
        ]);
        $thread->forceFill([
            'last_message_preview' => mb_substr($payload['body'], 0, 280),
            'last_message_at' => now(),
        ])->save();
        $proposal->update(['status' => 'executed', 'executed_at' => now()]);
        AuditLog::record('ai.guest_reply.sent', $thread, ['proposal_id' => $proposal->id], 'ai');

        return ['state' => 'sent', 'thread_id' => $thread->id, 'executed_at' => now()->toIso8601String()];
    }

    private function applyPricingRange(AiActionProposal $proposal): array
    {
        $payload = $proposal->payload;
        $result = DB::transaction(function () use ($proposal, $payload) {
            $lockedProposal = AiActionProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($lockedProposal->status === 'executed') {
                return ['state' => 'already_executed', 'count' => count($payload['days'] ?? [])];
            }
            if ($lockedProposal->status !== 'pending' || $lockedProposal->expires_at->isPast()) {
                throw new RuntimeException('This proposal is no longer pending.');
            }

            PricingRulesVersion::lock();
            $type = RoomType::query()->whereKey($payload['room_type_id'])->lockForUpdate()->firstOrFail();
            $from = Carbon::parse($payload['date_from'])->startOfDay();
            $to = Carbon::parse($payload['date_to'])->startOfDay();
            $fresh = collect(PricingEngine::forRange($type, $from, $to))
                ->filter(fn ($day) => $day['actionable'] && ! $day['is_past'])
                ->map(fn ($day) => ['date' => $day['date'], 'price' => round((float) $day['suggested_price'], 2)])
                ->values()->all();

            if ($fresh !== ($payload['days'] ?? [])) {
                throw new RuntimeException('Pricing inputs changed. Create a fresh proposal before applying.');
            }
            foreach ($fresh as $day) {
                if ($this->priceOutOfBand($day['price'], $type)) {
                    throw new RuntimeException("Suggested price for {$day['date']} is outside the hotel safety limits.");
                }
                RateOverride::updateOrCreate(
                    ['date' => $day['date'], 'room_type_id' => $type->id],
                    ['price' => $day['price'], 'created_by' => auth()->id()],
                );
            }
            $lockedProposal->update(['status' => 'executed', 'executed_at' => now()]);
            AuditLog::record('ai.pricing_range.applied', $type, [
                'proposal_id' => $proposal->id,
                'dates' => array_column($fresh, 'date'),
                'count' => count($fresh),
            ], 'ai');

            return ['state' => 'applied', 'count' => count($fresh), 'room_type_id' => $type->id];
        }, 3);

        if ($result['state'] === 'applied') {
            PushRoomTypeAri::dispatch($result['room_type_id']);
        }

        return $result;
    }

    private function priceOutOfBand(float $price, RoomType $type): bool
    {
        $base = (float) $type->base_price;
        [$min, $max] = $type->priceBounds();
        $min ??= $base > 0 ? $base * self::MIN_BAND : null;
        $max ??= $base > 0 ? $base * self::MAX_BAND : null;

        return $price <= 0 || ($min !== null && $price < $min) || ($max !== null && $price > $max);
    }
}
