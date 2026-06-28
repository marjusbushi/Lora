<script setup>
import { ref, onBeforeUnmount, nextTick } from 'vue';
import { MoreVertical } from 'lucide-vue-next';

// A compact three-dot (⋮) actions menu. Teleports its panel to <body> with fixed
// positioning so it is never clipped by a table's overflow-x-auto; a full-screen
// backdrop captures outside clicks. Put menu rows in the default slot — the panel
// closes on any click inside it (after the row's own @click runs), on Esc, and on
// page scroll/resize. Position flips above when there isn't room below, and the
// panel scrolls internally if it's taller than the available space.
const WIDTH = 192;

const open = ref(false);
const trigger = ref(null);
const panel = ref(null);
const style = ref({});

function position() {
    const r = trigger.value.getBoundingClientRect();
    const left = Math.max(8, Math.min(r.right - WIDTH, window.innerWidth - WIDTH - 8));
    const spaceBelow = window.innerHeight - r.bottom - 8;
    const spaceAbove = r.top - 8;
    const h = panel.value?.getBoundingClientRect().height ?? 0;
    if (h > spaceBelow && spaceAbove > spaceBelow) {
        // not enough room below and more room above → open upward
        style.value = { left: `${left}px`, bottom: `${window.innerHeight - r.top + 6}px`, width: `${WIDTH}px`, maxHeight: `${spaceAbove}px` };
    } else {
        style.value = { left: `${left}px`, top: `${r.bottom + 6}px`, width: `${WIDTH}px`, maxHeight: `${spaceBelow}px` };
    }
}

function onKey(e) {
    if (e.key === 'Escape') close();
}

function addListeners() {
    document.addEventListener('keydown', onKey);
    // capture phase also catches scrolling inside the table's overflow container
    window.addEventListener('scroll', close, true);
    window.addEventListener('resize', close);
}

function removeListeners() {
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('scroll', close, true);
    window.removeEventListener('resize', close);
}

function openMenu() {
    // provisional below-position so the first paint isn't at 0,0, then re-measure
    const r = trigger.value.getBoundingClientRect();
    const left = Math.max(8, Math.min(r.right - WIDTH, window.innerWidth - WIDTH - 8));
    style.value = { left: `${left}px`, top: `${r.bottom + 6}px`, width: `${WIDTH}px` };
    open.value = true;
    addListeners();
    nextTick(position); // flip + max-height from the real rendered height
}

function close() {
    if (!open.value) return;
    open.value = false;
    removeListeners();
}

function toggle() {
    open.value ? close() : openMenu();
}

onBeforeUnmount(removeListeners);
</script>

<template>
    <div class="inline-flex">
        <button
            ref="trigger"
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-neutral-700"
            :class="open && 'bg-neutral-100 text-neutral-700'"
            aria-haspopup="menu"
            :aria-expanded="open"
            aria-label="Veprime"
            @click.stop="toggle"
        >
            <MoreVertical class="h-[18px] w-[18px]" :stroke-width="2" />
        </button>

        <Teleport to="body">
            <template v-if="open">
                <div class="fixed inset-0 z-40" @click="close" />
                <div
                    ref="panel"
                    class="fixed z-50 overflow-y-auto rounded-lg border border-neutral-200 bg-white py-1 shadow-xl"
                    :style="style"
                    role="menu"
                    @click="close"
                >
                    <slot />
                </div>
            </template>
        </Teleport>
    </div>
</template>
