<script setup>
import { ref, computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    years:      { type: Array,  required: true },
    targetYear: { type: Object, required: true },
    sourceData: { type: Object, default: null },
})

const sourceYearId  = ref(props.sourceData?.source_year_id ?? null)
const targetYearId  = ref(props.targetYear.id)
const conflictResolution = ref('replace')  // 'replace' | 'skip'

function loadPreview() {
    if (!sourceYearId.value || !targetYearId.value) return
    router.get(
        route('schedule.import'),
        { source_year_id: sourceYearId.value, target_year_id: targetYearId.value },
        { preserveState: true }
    )
}

watch([sourceYearId, targetYearId], () => loadPreview())

// ─── Day / activity selection ────────────────────────────────────────────────
const dayChecked      = ref({})  // dayId -> boolean
const activityChecked = ref({})  // activityId -> boolean

function resetSelection() {
    const days = props.sourceData?.source_days ?? []
    const dc = {}, ac = {}
    for (const day of days) {
        dc[day.id] = true
        for (const act of day.activities) ac[act.id] = true
    }
    dayChecked.value = dc
    activityChecked.value = ac
}

watch(() => props.sourceData?.source_days, resetSelection, { immediate: true })

function toggleDay(day) {
    const checked = !dayChecked.value[day.id]
    dayChecked.value[day.id] = checked
    for (const act of day.activities) activityChecked.value[act.id] = checked
}

const selectedDayCount = computed(() =>
    (props.sourceData?.source_days ?? []).filter(d => dayChecked.value[d.id]).length
)

const form = useForm({
    source_year_id: props.sourceData?.source_year_id ?? null,
    target_year_id: props.targetYear.id,
    selected_day_ids: [],
    excluded_activity_ids: [],
})

function submit() {
    if (!sourceYearId.value || !targetYearId.value) return
    if (sourceYearId.value === targetYearId.value) return

    // If target has data and user chose to skip, do nothing
    if (props.sourceData?.target_has_data && conflictResolution.value === 'skip') {
        router.get(route('schedule.index', { year_id: targetYearId.value }))
        return
    }

    if (selectedDayCount.value === 0) return

    const days = props.sourceData?.source_days ?? []

    form.source_year_id = sourceYearId.value
    form.target_year_id = targetYearId.value
    form.selected_day_ids = days.filter(d => dayChecked.value[d.id]).map(d => d.id)
    form.excluded_activity_ids = days
        .filter(d => dayChecked.value[d.id])
        .flatMap(d => d.activities)
        .filter(a => !activityChecked.value[a.id])
        .map(a => a.id)
    form.post(route('schedule.import.store'))
}

const sameYear   = computed(() => sourceYearId.value === targetYearId.value)
const isSkipping = computed(() => targetHasData.value && conflictResolution.value === 'skip')

const sourceSummary = computed(() => props.sourceData?.source_summary)
const targetSummary = computed(() => props.sourceData?.target_summary)
const targetHasData = computed(() => props.sourceData?.target_has_data ?? false)
const sourceDays    = computed(() => props.sourceData?.source_days ?? [])

function fmtDayLabel(day) {
    const [y, m, d] = day.date.split('-').map(Number)
    const label = new Date(y, m - 1, d).toLocaleDateString('es-AR', {
        weekday: 'short', day: 'numeric', month: 'long',
    })
    return day.title ? `${label} · ${day.title}` : label
}

function fmtActTime(act) {
    if (act.start_time && act.end_time) return `${act.start_time}–${act.end_time}`
    if (act.start_time) return act.start_time
    return ''
}
</script>

<template>
    <AppLayout title="Importar cronograma">
        <template #header>
            <div class="flex items-center gap-4">
                <a :href="route('schedule.index')" class="text-xs text-indigo-600 hover:text-indigo-800 uppercase tracking-wide">
                    ← Cronograma
                </a>
                <h2 class="font-semibold text-xl text-gray-800 leading-tight">Importar cronograma</h2>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-xl mx-auto px-4 sm:px-6 space-y-6">

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-5">
                    <p class="text-sm text-gray-600">
                        Importar el cronograma de una edición anterior como base para una nueva.
                        Se copian títulos, instrucciones y horarios previstos.
                        No se copian estados, momentos reales ni observaciones.
                    </p>

                    <!-- Source year -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Edición origen (fuente)
                        </label>
                        <select
                            v-model="sourceYearId"
                            class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option :value="null">Seleccionar edición…</option>
                            <option
                                v-for="y in years"
                                :key="y.id"
                                :value="y.id"
                                :disabled="y.id === targetYearId"
                            >
                                {{ y.label }}{{ y.is_active ? ' (activa)' : '' }}
                            </option>
                        </select>
                    </div>

                    <!-- Target year -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Edición destino
                        </label>
                        <select
                            v-model="targetYearId"
                            class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option
                                v-for="y in years"
                                :key="y.id"
                                :value="y.id"
                                :disabled="y.id === sourceYearId"
                            >
                                {{ y.label }}{{ y.is_active ? ' (activa)' : '' }}
                            </option>
                        </select>
                    </div>

                    <!-- Error: same year -->
                    <p v-if="sameYear" class="text-sm text-red-600">
                        El origen y el destino no pueden ser la misma edición.
                    </p>

                    <!-- Preview -->
                    <div v-if="sourceData && !sameYear" class="rounded-lg bg-gray-50 border border-gray-200 p-4 space-y-3 text-sm">
                        <div class="flex justify-between text-gray-600">
                            <span>Días en origen:</span>
                            <span class="font-medium">{{ sourceSummary?.days ?? 0 }}</span>
                        </div>
                        <div class="flex justify-between text-gray-600">
                            <span>Actividades en origen:</span>
                            <span class="font-medium">{{ sourceSummary?.activities ?? 0 }}</span>
                        </div>

                        <template v-if="targetHasData">
                            <div class="border-t border-gray-200 pt-3">
                                <p class="text-amber-700 font-medium mb-2">
                                    ⚠ La edición destino ya tiene cronograma
                                    ({{ targetSummary?.days }} día(s) · {{ targetSummary?.activities }} actividad(es)).
                                </p>
                                <div class="space-y-2">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" v-model="conflictResolution" value="replace" class="text-indigo-600" />
                                        <span>Reemplazar con el cronograma de origen</span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" v-model="conflictResolution" value="skip" class="text-indigo-600" />
                                        <span>Mantener el cronograma actual (cancelar importación)</span>
                                    </label>
                                </div>
                            </div>
                        </template>

                        <p v-if="sourceSummary?.days === 0" class="text-amber-700">
                            La edición origen no tiene días en el cronograma.
                        </p>
                    </div>

                    <!-- Selection: days & activities -->
                    <div v-if="sourceDays.length > 0 && !sameYear" class="space-y-2">
                        <p class="text-sm font-medium text-gray-700">
                            Elegí qué días y actividades importar
                        </p>
                        <div class="rounded-lg border border-gray-200 divide-y divide-gray-100 max-h-80 overflow-y-auto">
                            <div v-for="day in sourceDays" :key="day.id" class="p-3">
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        :checked="!!dayChecked[day.id]"
                                        class="mt-0.5 rounded text-indigo-600 focus:ring-indigo-500"
                                        @change="toggleDay(day)"
                                    />
                                    <span class="text-sm font-medium text-gray-800">{{ fmtDayLabel(day) }}</span>
                                </label>

                                <div v-if="day.activities.length" class="mt-2 ml-6 space-y-1">
                                    <label
                                        v-for="act in day.activities"
                                        :key="act.id"
                                        class="flex items-center gap-2 cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            v-model="activityChecked[act.id]"
                                            :disabled="!dayChecked[day.id]"
                                            class="rounded text-indigo-600 focus:ring-indigo-500 disabled:opacity-40"
                                        />
                                        <span
                                            class="text-xs"
                                            :class="dayChecked[day.id] ? 'text-gray-600' : 'text-gray-300'"
                                        >
                                            <span v-if="fmtActTime(act)" class="font-mono mr-1">{{ fmtActTime(act) }}</span>
                                            {{ act.title }}
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <p v-if="selectedDayCount === 0" class="text-xs text-red-600">
                            Seleccioná al menos un día para importar.
                        </p>
                    </div>

                    <!-- Actions -->
                    <div class="flex gap-3 pt-2">
                        <button
                            type="button"
                            :disabled="!sourceYearId || !targetYearId || sameYear || form.processing || (sourceSummary?.days === 0) || (!isSkipping && selectedDayCount === 0)"
                            class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-lg hover:bg-indigo-700 disabled:opacity-40"
                            @click="submit"
                        >
                            <template v-if="targetHasData && conflictResolution === 'skip'">
                                Cancelar importación
                            </template>
                            <template v-else>
                                Importar cronograma
                            </template>
                        </button>
                        <a
                            :href="route('schedule.index')"
                            class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900"
                        >
                            Volver
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
