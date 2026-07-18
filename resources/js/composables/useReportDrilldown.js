import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useReportDrilldown() {
    const page = usePage();
    const permissions = computed(() => page.props.auth?.user?.permissions || []);
    const modules = computed(() => page.props.modules || {});

    const can = (permission) => permissions.value.includes(permission);
    const hasModule = (module) => !module || modules.value[module] === true;

    return { can, hasModule };
}
