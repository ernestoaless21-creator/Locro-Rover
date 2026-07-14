<script setup>
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    meetings:  Array,
    year:      Object,
    years:     Array,
    canManage: Boolean,
})

const selectedYearId = ref(props.year.id)

function changeYear() {
    router.get(route('meetings.index'), { year_id: selectedYearId.value }, { preserveState: false })
}

const grouped = computed(() => {
    const groups = {}
    for (const m of props.meetings) {
        const key = m.date.slice(0, 7)
        if (!groups[key]) groups[key] = []
        groups[key].push(m)
    }
    return groups
})

const monthLabel = (key) => {
    const [y, mon] = key.split('-')
    return new Date(Number(y), Number(mon) - 1, 1)
        .toLocaleDateString('es-AR', { month: 'long', year: 'numeric' })
}

const shortDate = (dateStr) =>
    new Date(dateStr + 'T00:00:00').toLocaleDateString('es-AR', {
        weekday: 'short', day: 'numeric', month: 'short',
    })
</script>

<template>
    <AppLayout title="Actas">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Actas y reuniones</h2>
                <div class="flex items-center gap-3">
                    <select
                        v-model="selectedYearId"
                        class="border-gray-300 rounded-md text-sm shadow-sm focus:ring-indigo-500 focus:border-indigo-500"
                        @change="changeYear"
                    >
                        <option v-for="y in years" :key="y.id" :value="y.id">{{ y.label }}</option>
                    </select>
                    <Link
                        v-if="canManage"
                        :href="route('meetings.create', { year_id: year.id })"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition"
                    >
                        + Nueva acta
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

                <div v-if="meetings.length === 0" class="text-center py-16 text-gray-500">
                    No hay actas registradas para {{ year.label }}.
                </div>

                <div v-else class="space-y-8">
                    <div v-for="(items, monthKey) in grouped" :key="monthKey">
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-400 mb-3 capitalize">
                            {{ monthLabel(monthKey) }}
                        </h3>
                        <div class="space-y-1">
                            <Link
                                v-for="m in items"
                                :key="m.id"
                                :href="route('meetings.show', m.id)"
                                class="flex items-start gap-4 bg-white border border-gray-200 rounded-lg px-5 py-3.5 hover:border-indigo-300 hover:shadow-sm transition group"
                            >
                                <span class="shrink-0 w-28 text-sm text-gray-400 pt-0.5 capitalize">
                                    {{ shortDate(m.date) }}
                                </span>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-900 group-hover:text-indigo-700 truncate">{{ m.title }}</p>
                                    <p v-if="m.secretary_name" class="text-xs text-gray-400 mt-0.5">
                                        Sec. {{ m.secretary_name }}
                                    </p>
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
