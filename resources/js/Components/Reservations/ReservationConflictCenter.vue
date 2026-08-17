<script setup>
import { ref } from 'vue';
import { getIntlLocale } from '@/i18n';
import Button from '@/Components/UI/Button.vue';
import { AlertTriangle, ArrowRight, BedDouble, CheckCircle2, ChevronDown, Shuffle, X } from 'lucide-vue-next';

defineProps({
    conflicts: { type: Array, default: () => [] },
    demo: { type: Boolean, default: false },
    resolvingReservationId: { type: Number, default: null },
});

const emit = defineEmits(['close', 'open-reservation', 'apply-suggestion', 'apply-reshuffle']);

// Cross-type rooms change the guest's booked product — hidden until staff
// explicitly asks, per reservation.
const crossTypeOpen = ref({});

function toggleCrossType(reservationId) {
    crossTypeOpen.value = { ...crossTypeOpen.value, [reservationId]: !crossTypeOpen.value[reservationId] };
}

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
                                                <p class="text-[10px] font-semibold text-success-700">{{ $t('admin.calendarConflicts.sameType') }} · {{ room.room_type }}</p>
                                            </div>
                                            <Button size="sm" variant="success" :loading="resolvingReservationId === reservation.id" :disabled="resolvingReservationId !== null" @click="emit('apply-suggestion', { conflictId: conflict.id, reservationId: reservation.id, room })">
                                                {{ $t('admin.calendarConflicts.choose') }} <ArrowRight class="h-3.5 w-3.5" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="reservation.reshuffle_plan" class="mt-3 rounded-lg border border-info-100 bg-info-50 p-3">
                                    <div class="mb-2 flex items-center gap-2 text-tiny font-bold text-info-800">
                                        <Shuffle class="h-4 w-4" />
                                        {{ $t('admin.calendarConflicts.reshuffleTitle') }}
                                    </div>
                                    <ul class="mb-2 space-y-1">
                                        <li v-for="move in reservation.reshuffle_plan.moves" :key="move.reservation_id" class="text-tiny text-neutral-600">
                                            {{ $t('admin.calendarConflicts.reshuffleMove', { from: move.from_room_number, to: move.to_room_number, guest: move.guest_name }) }}
                                        </li>
                                        <li class="text-tiny font-semibold text-info-800">
                                            {{ $t('admin.calendarConflicts.reshuffleLanding', { room: reservation.reshuffle_plan.room.room_number }) }}
                                        </li>
                                    </ul>
                                    <Button size="sm" variant="primary" :loading="resolvingReservationId === reservation.id" :disabled="resolvingReservationId !== null" @click="emit('apply-reshuffle', { conflictId: conflict.id, reservationId: reservation.id, plan: reservation.reshuffle_plan })">
                                        {{ $t('admin.calendarConflicts.reshuffleApply', { n: reservation.reshuffle_plan.moves.length }) }} <ArrowRight class="h-3.5 w-3.5" />
                                    </Button>
                                </div>

                                <div v-if="reservation.cross_type_rooms?.length" class="mt-3">
                                    <button
                                        type="button"
                                        class="flex w-full items-center justify-between rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-tiny font-bold text-warning-800 hover:bg-warning-100"
                                        :aria-expanded="!!crossTypeOpen[reservation.id]"
                                        @click="toggleCrossType(reservation.id)"
                                    >
                                        <span class="flex items-center gap-2"><AlertTriangle class="h-4 w-4" /> {{ $t('admin.calendarConflicts.showOtherTypes') }}</span>
                                        <ChevronDown class="h-4 w-4 transition-transform" :class="crossTypeOpen[reservation.id] ? 'rotate-180' : ''" />
                                    </button>
                                    <div v-if="crossTypeOpen[reservation.id]" class="mt-2 rounded-lg border border-warning-200 bg-warning-50/50 p-3">
                                        <p class="mb-2 text-tiny font-semibold text-warning-800">{{ $t('admin.calendarConflicts.otherTypesWarning') }}</p>
                                        <div class="space-y-2">
                                            <div v-for="room in reservation.cross_type_rooms" :key="room.id" class="flex items-center justify-between gap-3 rounded-lg border border-warning-200 bg-white p-2.5">
                                                <div>
                                                    <p class="text-body-sm font-bold text-primary-900">{{ $t('admin.calendarConflicts.moveToRoom', { room: room.room_number }) }}</p>
                                                    <p class="text-[10px] font-semibold text-warning-700">{{ $t('admin.calendarConflicts.alternativeType') }} · {{ room.room_type }}</p>
                                                </div>
                                                <Button size="sm" variant="outline" :loading="resolvingReservationId === reservation.id" :disabled="resolvingReservationId !== null" @click="emit('apply-suggestion', { conflictId: conflict.id, reservationId: reservation.id, room })">
                                                    {{ $t('admin.calendarConflicts.choose') }} <ArrowRight class="h-3.5 w-3.5" />
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <p v-if="!reservation.suggested_rooms?.length && !reservation.reshuffle_plan && !reservation.cross_type_rooms?.length" class="mt-3 rounded-lg px-3 py-2 text-tiny" :class="reservation.keep_in_room ? 'bg-neutral-50 text-neutral-500' : 'bg-warning-50 text-warning-800'">
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
