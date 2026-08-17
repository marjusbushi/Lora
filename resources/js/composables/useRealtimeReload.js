import { onBeforeUnmount, onMounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { getEcho } from '@/echo';

/**
 * Rifreskim live i pjesshëm (task #345): dëgjon një event në kanalin privat
 * të tenantit dhe bën router.reload({ only }) me DEBOUNCE — një stuhi
 * ngjarjesh (import OTA në masë) bëhet NJË reload i vetëm. Reload-i i
 * pjesshëm nuk prek gjendjen lokale të komponentëve (modalet/format e hapura
 * mbeten të paprekura). Tab-i në sfond NUK rifreskohet — kapet me një reload
 * të vetëm kur rikthehet i dukshëm.
 */
export function useRealtimeReload(channelSuffix, eventName, onlyProps, { debounceMs = 400 } = {}) {
    const tenantId = usePage().props.tenant?.id;
    const channelName = `tenant.${tenantId}.${channelSuffix}`;
    let timer = null;
    let pendingWhileHidden = false;

    function reload() {
        if (document.hidden) {
            pendingWhileHidden = true;
            return;
        }
        router.reload({ only: onlyProps, preserveScroll: true });
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(reload, debounceMs);
    }

    function onVisibility() {
        if (!document.hidden && pendingWhileHidden) {
            pendingWhileHidden = false;
            reload();
        }
    }

    onMounted(() => {
        if (tenantId) {
            getEcho()?.private(channelName).listen(eventName, schedule);
        }
        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisibility);
        if (tenantId) getEcho()?.leave(`private-${channelName}`);
    });
}
