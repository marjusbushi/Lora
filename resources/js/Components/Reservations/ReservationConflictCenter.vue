<script setup>
import { getIntlLocale } from '@/i18n';
import Button from '@/Components/UI/Button.vue';
import { AlertTriangle, ArrowRight, BedDouble, CheckCircle2, X } from 'lucide-vue-next';

defineProps({
    conflicts: { type: Array, default: () => [] },
    demo: { type: Boolean, default: false },
    resolvingReservationId: { type: Number, default: null },
});

const emit = defineEmits(['close', 'open-reservation', 'apply-suggestion']);

function formatDate(value) {
    if (!value) return '—';
    return new Intl.DateTimeFormat(getIntlLocale(), {
        day: '2-digit', month: 'short', year: 'numeric',
    }).format(new Date(`${value}T12:00:00`));
}

function guestName(reservation) {
    return `${reservation.guest?.first_name || ''} ${reservation.guest?.last_name || ''}`.trim();
}
</script>

<template>
    <Teleport to="body">
        <Transition name="conflict-fade">
            <button
                type="button"
                class="fixed inset-0 z-[60] cursor-default bg-primary-950/25 backdrop-blur-[1px]"
                :aria-label="$t('admin.calendarConflicts.close')"
                @click="emit('close')"
            />
        </Transition>
        <Transition name="conflict-slide">
            <aside
                class="fixed inset-y-0 right-0 z-[70] flex w-full max-w-xl flex-col border-l border-error-200 bg-neutral-50 shadow-2xl"
                role="dialog"
                aria-modal="true"
                :aria-label="$t('admin.calendarConflicts.centerTitle')"
            >
                <header class="shrink-0 border-b border-error-100 bg-white px-5 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-error-100 text-error-700">
                                <AlertTriangle class="h-5 w-5" />
                            </span>
                            <div>
                                <h2 class="text-h3 text-primary-900">{{ $t('admin.calendarConflicts.centerTitle') }}</h2>
                                <p class="mt-1 text-body-sm text-neutral-500">{{ $t('admin.calendarConflicts.centerSubtitle', { count: conflicts.length }) }}</p>
                            </div>
                        </div>
                        <button type="button" class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700" @click="emit('close')">
                            <X class="h-5 w-5" />
                        </button>
                    </div>
                </header>

                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                    <div class="rounded-xl border border-info-100 bg-info-50 p-3 text-body-sm text-info-800">
                        {{ demo ? $t('admin.calendarConflicts.demoNotice') : $t('admin.calendarConflicts.realNotice') }}
                    </div>

                    <article v-for="conflict in conflicts" :key="conflict.id" class="overflow-hidden rounded-xl border border-error-200 bg-white shadow-card">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-error-100 bg-error-50 px-4 py-3">
                            <div class="flex items-center gap-2">
                                <BedDouble class="h-4 w-4 text-error-700" />
                                <div>
                                    <p class="text-body-sm font-extrabold text-primary-900">{{ $t('admin.calendarConflicts.room') }} {{ conflict.room_number }} · {{ conflict.room_type }}</p>
                                    <p class="text-tiny text-error-700">{{ $t('admin.calendarConflicts.conflictPeriod') }}: {{ formatDate(conflict.start_date) }} – {{ formatDate(conflict.end_date) }}</p>
                                </div>
                            </div>
                            <span class="rounded-full bg-error-600 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-white">{{ $t('admin.calendarConflicts.actionRequired') }}</span>
                        </div>

                        <div class="divide-y divide-neutral-100">
                            <section v-for="reservation in conflict.reservations" :key="reservation.id" class="p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="truncate text-body-sm font-extrabold text-primary-900">{{ guestName(reservation) }}</p>
                                        <p class="mt-0.5 text-tiny text-neutral-500">{{ formatDate(reservation.check_in_date) }} → {{ formatDate(reservation.check_out_date) }} · #{{ reservation.channel_ref }}</p>
                                    </div>
                                    <button type="button" class="shrink-0 text-tiny font-semibold text-accent-700 hover:text-accent-800" @click="emit('open-reservation', reservation.id)">
                                        {{ $t('admin.calendarConflicts.viewDetails') }}
                                    </button>
                                </div>

                                <div v-if="reservation.suggested_rooms?.length" class="mt-3 rounded-lg border border-success-100 bg-success-50/60 p-3">
                                    <div class="mb-2 flex items-center gap-2 text-tiny font-bold text-success-800">
                                        <CheckCircle2 class="h-4 w-4" />
                                        {{ $t('admin.calendarConflicts.suggestedSolution') }}
                                    </div>
                                    <div class="space-y-2">
                                        <div v-for="room in reservation.suggested_rooms" :key="room.id" class="flex items-center justify-between gap-3 rounded-lg border border-success-200 bg-white p-2.5">
                                            <div>
                                                <p class="text-body-sm font-bold text-primary-900">{{ $t('admin.calendarConflicts.moveToRoom', { room: room.room_number }) }}</p>
                                                <p class="text-[10px] font-semibold" :class="room.same_type ? 'text-success-700' : 'text-warning-700'">{{ room.same_type ? $t('admin.calendarConflicts.sameType') : $t('admin.calendarConflicts.alternativeType') }} · {{ room.room_type }}</p>
                                            </div>
                                            <Button size="sm" :variant="room.same_type ? 'success' : 'outline'" :loading="resolvingReservationId === reservation.id" :disabled="resolvingReservationId !== null" @click="emit('apply-suggestion', { conflictId: conflict.id, reservationId: reservation.id, room })">
                                                {{ $t('admin.calendarConflicts.choose') }} <ArrowRight class="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="mt-3 rounded-lg px-3 py-2 text-tiny" :class="reservation.keep_in_room ? 'bg-neutral-50 text-neutral-500' : 'bg-warning-50 text-warning-800'">
                                    {{ reservation.keep_in_room ? $t('admin.calendarConflicts.keepReservation') : $t('admin.calendarConflicts.noSuggestion') }}
                                </p>
                            </section>
                        </div>
                    </article>
                </div>
            </aside>
        </Transition>
    </Teleport>
</template>

<style scoped>
.conflict-slide-enter-active,
.conflict-slide-leave-active,
.conflict-fade-enter-active,
.conflict-fade-leave-active {
    transition: opacity 180ms ease, transform 220ms cubic-bezier(0.4, 0, 0.2, 1);
}
.conflict-slide-enter-from,
.conflict-slide-leave-to { transform: translateX(100%); }
.conflict-fade-enter-from,
.conflict-fade-leave-to { opacity: 0; }
</style>
