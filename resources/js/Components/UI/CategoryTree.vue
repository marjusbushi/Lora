<script setup>
import { computed, ref } from 'vue';
import { ChevronDown, ChevronRight } from 'lucide-vue-next';

// Flat, tree-ordered nodes {id, parent_id, depth, category, ...} — the shape
// every category report emits. Roots always show; deeper levels reveal on
// expand. The row content itself is the caller's slot.
const props = defineProps({
    nodes: { type: Array, default: () => [] },
    expandLabel: { type: String, default: '' },
});

const expanded = ref(new Set());

const childCount = computed(() => {
    const counts = new Map();
    for (const node of props.nodes) {
        if (node.parent_id != null) counts.set(node.parent_id, (counts.get(node.parent_id) || 0) + 1);
    }
    return counts;
});

const byId = computed(() => new Map(props.nodes.map((node) => [node.id, node])));

const visible = computed(() => props.nodes.filter((node) => {
    let parent = node.parent_id != null ? byId.value.get(node.parent_id) : null;
    while (parent) {
        if (!expanded.value.has(parent.id)) return false;
        parent = parent.parent_id != null ? byId.value.get(parent.parent_id) : null;
    }
    return true;
}));

function toggle(node) {
    const next = new Set(expanded.value);
    if (next.has(node.id)) next.delete(node.id);
    else next.add(node.id);
    expanded.value = next;
}

const indentClass = (depth) => ({ 0: '', 1: 'pl-6', 2: 'pl-12' }[depth] || 'pl-12');
</script>

<template>
    <ul class="divide-y divide-neutral-100">
        <li v-for="node in visible" :key="node.id ?? 'uncategorized'" class="px-5 py-3">
            <div class="flex items-center gap-2" :class="indentClass(node.depth)">
                <button
                    v-if="childCount.get(node.id)"
                    type="button"
                    class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded text-neutral-500 hover:bg-neutral-100 hover:text-primary-900 focus:outline-none focus:ring-2 focus:ring-accent-500"
                    :aria-expanded="expanded.has(node.id)"
                    :aria-label="`${expandLabel} ${node.category}`"
                    @click="toggle(node)"
                >
                    <ChevronDown v-if="expanded.has(node.id)" class="h-4 w-4" aria-hidden="true" />
                    <ChevronRight v-else class="h-4 w-4" aria-hidden="true" />
                </button>
                <span v-else class="w-6 shrink-0" aria-hidden="true" />
                <div class="min-w-0 flex-1">
                    <slot :node="node" :is-expanded="expanded.has(node.id)" />
                </div>
            </div>
        </li>
    </ul>
</template>
