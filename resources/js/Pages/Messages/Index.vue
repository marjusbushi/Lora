<script setup>
import { computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps({
    threads: Array,
    selected: Object,
});

const replyForm = useForm({ body: '' });

const CHANNELS = {
    'booking.com': 'Booking.com',
    booking: 'Booking.com',
    airbnb: 'Airbnb',
    expedia: 'Expedia',
    agoda: 'Agoda',
};
function channelLabel(c) {
    return CHANNELS[c] || c || 'OTA';
}

function time(value) {
    if (!value) return '';
    return new Intl.DateTimeFormat('sq-AL', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

function openThread(id) {
    router.get(route('messages.index'), { thread: id }, { preserveState: true, preserveScroll: true });
}

function sendReply() {
    if (!props.selected) return;
    replyForm.post(route('messages.reply', props.selected.id), {
        preserveScroll: true,
        onSuccess: () => replyForm.reset('body'),
    });
}
</script>

<template>
    <Head title="Mesazhet" />

    <AppLayout>
        <div class="space-y-4">
            <PageHeader title="Mesazhet" subtitle="Bisedat me mysafirët nga Booking, Airbnb dhe Expedia — përgjigju nga këtu." />

            <div v-if="!threads.length" class="rounded-xl border border-neutral-200 bg-white px-6 py-16 text-center">
                <p class="text-sm font-medium text-neutral-700">Ende asnjë bisedë</p>
                <p class="mt-1 text-sm text-neutral-500">Kur një mysafir të shkruajë nga një OTA, biseda do të shfaqet këtu.</p>
            </div>

            <div v-else class="grid gap-4 lg:grid-cols-[340px_1fr]">
                <!-- Thread list -->
                <aside class="overflow-hidden rounded-xl border border-neutral-200 bg-white">
                    <ul class="max-h-[70vh] divide-y divide-neutral-100 overflow-y-auto">
                        <li v-for="t in threads" :key="t.id">
                            <button
                                type="button"
                                class="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-neutral-50"
                                :class="selected && selected.id === t.id ? 'bg-emerald-50/60' : ''"
                                @click="openThread(t.id)"
                            >
                                <span class="mt-1 h-2 w-2 shrink-0 rounded-full" :class="t.unread > 0 ? 'bg-emerald-500' : 'bg-transparent'" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-semibold text-neutral-900">{{ t.guest_name }}</span>
                                        <span class="shrink-0 text-[11px] text-neutral-400">{{ time(t.last_message_at) }}</span>
                                    </span>
                                    <span class="mt-0.5 flex items-center gap-1.5">
                                        <span class="rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] font-medium text-neutral-500">{{ channelLabel(t.channel) }}</span>
                                        <span class="truncate text-xs text-neutral-500">{{ t.preview }}</span>
                                    </span>
                                </span>
                                <span v-if="t.unread > 0" class="ml-1 shrink-0 rounded-full bg-emerald-600 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ t.unread }}</span>
                            </button>
                        </li>
                    </ul>
                </aside>

                <!-- Conversation -->
                <section class="flex min-h-[70vh] flex-col overflow-hidden rounded-xl border border-neutral-200 bg-white">
                    <div v-if="selected" class="flex items-center justify-between border-b border-neutral-200 px-5 py-3">
                        <div>
                            <h2 class="font-semibold text-neutral-900">{{ selected.guest_name || 'Mysafir' }}</h2>
                            <p class="text-xs text-neutral-500">{{ channelLabel(selected.channel) }}</p>
                        </div>
                    </div>

                    <div v-if="selected" class="flex-1 space-y-3 overflow-y-auto bg-neutral-50/40 px-5 py-4">
                        <div v-for="m in selected.messages" :key="m.id" class="flex" :class="m.sender === 'host' ? 'justify-end' : 'justify-start'">
                            <div class="max-w-[78%] rounded-2xl px-4 py-2 text-sm shadow-sm"
                                :class="m.sender === 'host' ? 'bg-emerald-600 text-white' : 'bg-white text-neutral-800 border border-neutral-200'">
                                <p class="whitespace-pre-wrap break-words">{{ m.body }}</p>
                                <p class="mt-1 text-[10px]" :class="m.sender === 'host' ? 'text-emerald-100' : 'text-neutral-400'">{{ time(m.sent_at) }}</p>
                            </div>
                        </div>
                        <p v-if="!selected.messages.length" class="py-8 text-center text-sm text-neutral-400">Ende asnjë mesazh në këtë bisedë.</p>
                    </div>

                    <form v-if="selected && selected.can_reply" class="flex items-end gap-2 border-t border-neutral-200 p-3" @submit.prevent="sendReply">
                        <textarea
                            v-model="replyForm.body"
                            rows="2"
                            required
                            placeholder="Shkruaj përgjigjen…"
                            class="flex-1 resize-none rounded-lg border-neutral-300 text-sm"
                            @keydown.enter.exact.prevent="sendReply"
                        />
                        <Button type="submit" :disabled="replyForm.processing || !replyForm.body.trim()">Dërgo</Button>
                    </form>
                    <p v-else-if="selected" class="border-t border-neutral-200 px-5 py-3 text-xs text-neutral-400">Kjo bisedë s'lejon përgjigje.</p>

                    <div v-if="!selected" class="flex flex-1 items-center justify-center text-sm text-neutral-400">Zgjidh një bisedë majtas.</div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
