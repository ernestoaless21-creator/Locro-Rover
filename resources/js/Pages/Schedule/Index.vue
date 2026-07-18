<script setup>
import { ref, computed, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import HistoricalEditionBanner from '@/Components/HistoricalEditionBanner.vue'
import { useEditableYear } from '@/Composables/useEditableYear'

const props = defineProps({
    year:          { type: Object,  required: true },
    days:          { type: Array,   required: true },
    scheduleNotes: { type: String,  default: null },
    canManage:     { type: Boolean, required: true },
    teams:         { type: Array,   required: true },
})

const canMutateYear = useEditableYear(() => props.year)
const canManageNow = computed(() => props.canManage && canMutateYear.value)

// ─── Local reactive copy for optimistic reorder ──────────────────────────────
const localDays = ref(props.days.map(d => ({ ...d, activities: [...(d.activities ?? [])] })))

watch(
    () => props.days,
    (val) => { localDays.value = val.map(d => ({ ...d, activities: [...(d.activities ?? [])] })) },
    { deep: true }
)

// ─── UI state ────────────────────────────────────────────────────────────────
const showNotesForm    = ref(false)
const showNewDayForm   = ref(false)
const editingDayId     = ref(null)
const newActForDayId   = ref(null)   // which day's "new activity" form is open
const editingActId     = ref(null)   // definition (title/time/team) edit open for this activity
const editingExecId    = ref(null)   // execution (real date/time/notes) edit open for this activity

// ─── Forms ───────────────────────────────────────────────────────────────────
const notesForm = useForm({ notes: props.scheduleNotes ?? '', year_id: props.year.id })

const dayForm = useForm({ date: '', title: '', description: '', year_id: props.year.id })

const actForm = useForm({
    title: '', description: '',
    start_time: '', end_time: '',
    team: '',
})

const execForm = useForm({ actual_date: '', actual_time: '', notes: '' })

const showTimeFields = ref(false)

// ─── Helpers ─────────────────────────────────────────────────────────────────
const TEAM_LABELS = {
    logistica: 'Logística', compras: 'Compras',
    infraestructura: 'Infraestructura', publicidad: 'Publicidad',
}

function fmtDate(str) {
    if (!str) return ''
    const [y, m, d] = String(str).split('T')[0].split('-').map(Number)
    return new Date(y, m - 1, d).toLocaleDateString('es-AR', {
        weekday: 'short', day: 'numeric', month: 'long',
    })
}

function fmtShortDate(str) {
    if (!str) return ''
    const [y, m, d] = String(str).split('T')[0].split('-').map(Number)
    return new Date(y, m - 1, d).toLocaleDateString('es-AR', { day: 'numeric', month: 'short' })
}

function fmtTime(str) {
    if (!str) return ''
    return str.slice(0, 5)
}

// Diff in minutes between the scheduled moment (day.date + start_time) and
// the real moment (actual_date + actual_time). Null when there isn't enough
// information on both sides to compare meaningfully.
function diffMinutes(day, activity) {
    if (!activity.start_time || !activity.actual_date || !activity.actual_time) return null
    const dayDate = String(day.date).split('T')[0]
    const previsto = new Date(`${dayDate}T${activity.start_time}:00`)
    const real      = new Date(`${activity.actual_date}T${activity.actual_time}:00`)
    if (Number.isNaN(previsto.getTime()) || Number.isNaN(real.getTime())) return null
    return Math.round((real - previsto) / 60000)
}

function fmtDiff(day, activity) {
    const diff = diffMinutes(day, activity)
    if (diff === null) return null
    if (diff === 0) return 'A horario'
    const abs   = Math.abs(diff)
    const days  = Math.floor(abs / 1440)
    const hours = Math.floor((abs % 1440) / 60)
    const mins  = abs % 60
    const sign  = diff > 0 ? '+' : '−'
    const parts = []
    if (days > 0)  parts.push(`${days}d`)
    if (hours > 0) parts.push(`${hours}h`)
    if (mins > 0 || parts.length === 0) parts.push(`${mins}min`)
    return `${sign}${parts.join(' ')}`
}

function statusIcon(status) {
    if (status === 'completed') return '✓'
    if (status === 'skipped')   return '⊘'
    return '○'
}

function statusClass(status) {
    if (status === 'completed') return 'text-green-600 font-bold'
    if (status === 'skipped')   return 'text-gray-400'
    return 'text-gray-400'
}

function titleClass(status) {
    // Realizada: sin tachado — el check + "Realizada" ya comunican el estado,
    // y el cronograma también funciona como memoria histórica (debe seguir
    // siendo legible después de completarse). Fase 18 (ajuste fino de
    // contraste): estos valores son para texto sobre el fondo oscuro de la
    // pagina (ink), no sobre las tarjetas claras de los formularios.
    if (status === 'completed') return 'text-gray-300'
    if (status === 'skipped')   return 'text-gray-500 line-through'
    return 'text-white'
}

function diffClass(diff) {
    if (!diff || diff === 'A horario') return 'text-green-600'
    return diff.startsWith('+') ? 'text-amber-600' : 'text-blue-600'
}

function isSameDay(day, activity) {
    if (!activity.actual_date) return false
    return String(day.date).split('T')[0] === String(activity.actual_date).split('T')[0]
}

// ─── Notes ───────────────────────────────────────────────────────────────────
function openNotesForm() {
    notesForm.notes = props.scheduleNotes ?? ''
    showNotesForm.value = true
}

function submitNotes() {
    notesForm.put(route('schedule.notes.update'), {
        onSuccess: () => { showNotesForm.value = false },
    })
}

// ─── Days ─────────────────────────────────────────────────────────────────────
function openNewDay() {
    dayForm.reset()
    dayForm.year_id = props.year.id
    showNewDayForm.value = true
}

function openEditDay(day) {
    dayForm.date        = day.date ? String(day.date).split('T')[0] : ''
    dayForm.title       = day.title ?? ''
    dayForm.description = day.description ?? ''
    dayForm.year_id     = props.year.id
    editingDayId.value  = day.id
}

function submitDay() {
    if (editingDayId.value) {
        dayForm.put(route('schedule.days.update', editingDayId.value), {
            onSuccess: () => { editingDayId.value = null },
        })
    } else {
        dayForm.post(route('schedule.days.store'), {
            onSuccess: () => { showNewDayForm.value = false; dayForm.reset() },
        })
    }
}

function deleteDay(id) {
    if (!confirm('¿Eliminar este día y todas sus actividades?')) return
    router.delete(route('schedule.days.destroy', id), { preserveScroll: true })
}

function reorderDay(dayId, direction) {
    const idx = localDays.value.findIndex(d => d.id === dayId)
    if (idx === -1) return
    const newIdx = direction === 'up' ? idx - 1 : idx + 1
    if (newIdx < 0 || newIdx >= localDays.value.length) return
    const arr = [...localDays.value]
    ;[arr[idx], arr[newIdx]] = [arr[newIdx], arr[idx]]
    localDays.value = arr
    router.post(route('schedule.days.reorder'), { ids: arr.map(d => d.id) }, { preserveScroll: true })
}

// ─── Activities ───────────────────────────────────────────────────────────────
function openNewActivity(dayId) {
    actForm.reset()
    showTimeFields.value = false
    newActForDayId.value  = dayId
    editingActId.value    = null
    editingExecId.value   = null
}

function openEditActivity(activity) {
    actForm.title       = activity.title
    actForm.description = activity.description ?? ''
    actForm.start_time  = activity.start_time  ?? ''
    actForm.end_time    = activity.end_time    ?? ''
    actForm.team        = activity.team        ?? ''
    showTimeFields.value  = !!(activity.start_time)
    editingActId.value    = activity.id
    newActForDayId.value  = activity.schedule_day_id
    editingExecId.value   = null
}

function submitActivity(dayId) {
    const payload = {
        title:       actForm.title,
        description: actForm.description || null,
        start_time:  showTimeFields.value ? (actForm.start_time || null) : null,
        end_time:    showTimeFields.value ? (actForm.end_time   || null) : null,
        team:        actForm.team         || null,
    }

    if (editingActId.value) {
        router.put(
            route('schedule.activities.update', { day: dayId, activity: editingActId.value }),
            payload,
            {
                preserveScroll: true,
                onSuccess: () => { editingActId.value = null; newActForDayId.value = null },
            }
        )
    } else {
        router.post(
            route('schedule.activities.store', dayId),
            payload,
            {
                preserveScroll: true,
                onSuccess: () => { newActForDayId.value = null; actForm.reset() },
            }
        )
    }
}

function deleteActivity(dayId, actId) {
    if (!confirm('¿Eliminar esta actividad?')) return
    router.delete(route('schedule.activities.destroy', { day: dayId, activity: actId }), { preserveScroll: true })
}

// "Marcar realizada": changes status only, never invents a real date/time.
function markDone(dayId, actId) {
    router.post(
        route('schedule.activities.status', { day: dayId, activity: actId }),
        { status: 'completed' },
        { preserveScroll: true }
    )
}

// "Completar ahora": changes status AND records the current date/time.
function completeNow(dayId, actId) {
    router.post(
        route('schedule.activities.status', { day: dayId, activity: actId }),
        { status: 'completed', complete_now: true },
        { preserveScroll: true }
    )
}

function skipActivity(dayId, actId) {
    router.post(
        route('schedule.activities.status', { day: dayId, activity: actId }),
        { status: 'skipped' },
        { preserveScroll: true }
    )
}

// "Volver a pendiente": also clears whatever real date/time had been recorded.
function resetActivity(dayId, actId) {
    editingExecId.value = null
    router.post(
        route('schedule.activities.status', { day: dayId, activity: actId }),
        { status: 'pending' },
        { preserveScroll: true }
    )
}

// ─── Execution (real date/time/observation) ────────────────────────────────────
function openEditExecution(activity) {
    execForm.reset()
    execForm.actual_date = activity.actual_date ? String(activity.actual_date).split('T')[0] : ''
    execForm.actual_time = activity.actual_time ?? ''
    execForm.notes       = activity.notes ?? ''
    editingExecId.value  = activity.id
    editingActId.value   = null
    newActForDayId.value = null
}

function submitExecution(dayId, actId) {
    execForm.put(route('schedule.activities.execution', { day: dayId, activity: actId }), {
        preserveScroll: true,
        onSuccess: () => { editingExecId.value = null },
    })
}

// ─── Reorder (only among activities without a scheduled start_time) ───────────
function untimedActivities(day) {
    return day.activities.filter(a => !a.start_time)
}

function isFirstUntimed(day, act) {
    const arr = untimedActivities(day)
    return arr.length === 0 || arr[0].id === act.id
}

function isLastUntimed(day, act) {
    const arr = untimedActivities(day)
    return arr.length === 0 || arr[arr.length - 1].id === act.id
}

function reorderActivity(dayId, actId, direction) {
    const day = localDays.value.find(d => d.id === dayId)
    if (!day) return
    const untimed = untimedActivities(day)
    const idx = untimed.findIndex(a => a.id === actId)
    if (idx === -1) return
    const newIdx = direction === 'up' ? idx - 1 : idx + 1
    if (newIdx < 0 || newIdx >= untimed.length) return
    ;[untimed[idx], untimed[newIdx]] = [untimed[newIdx], untimed[idx]]
    const timed = day.activities.filter(a => a.start_time)
    day.activities = [...untimed, ...timed]
    router.post(
        route('schedule.activities.reorder', dayId),
        { ids: untimed.map(a => a.id) },
        { preserveScroll: true }
    )
}

// ─── Computed: is empty ───────────────────────────────────────────────────────
const isEmpty = computed(() => localDays.value.length === 0)
</script>

<template>
    <AppLayout title="Cronograma">
        <template #header>
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <h2 class="font-semibold text-xl text-white leading-tight">
                    Cronograma {{ year.label }}
                </h2>
                <div class="flex items-center gap-3">
                    <YearSelector :selectedYearId="year.id" />
                    <a
                        v-if="canManage"
                        :href="route('schedule.import')"
                        class="text-sm text-ember hover:text-ember-strong"
                    >
                        Importar edición anterior
                    </a>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <HistoricalEditionBanner :year="year" />

                <!-- ─── Notas generales ─────────────────────────────────── -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">
                            Notas generales
                        </h3>
                        <button
                            v-if="canManageNow && !showNotesForm"
                            type="button"
                            class="text-xs text-indigo-600 hover:text-indigo-800"
                            @click="openNotesForm"
                        >
                            Editar
                        </button>
                    </div>

                    <div class="px-5 py-4">
                        <!-- Edit mode -->
                        <form v-if="showNotesForm" @submit.prevent="submitNotes" class="space-y-3">
                            <textarea
                                v-model="notesForm.notes"
                                rows="6"
                                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                placeholder="Instrucciones, indicaciones importantes, ideas para la próxima edición…"
                            />
                            <p v-if="notesForm.errors.notes" class="text-xs text-red-600">{{ notesForm.errors.notes }}</p>
                            <div class="flex gap-2">
                                <button
                                    type="submit"
                                    :disabled="notesForm.processing"
                                    class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-500 disabled:opacity-50"
                                >
                                    Guardar
                                </button>
                                <button
                                    type="button"
                                    class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900"
                                    @click="showNotesForm = false"
                                >
                                    Cancelar
                                </button>
                            </div>
                        </form>

                        <!-- View mode -->
                        <div v-else>
                            <p
                                v-if="scheduleNotes"
                                class="text-sm text-gray-700 whitespace-pre-wrap leading-relaxed"
                            >{{ scheduleNotes }}</p>
                            <p v-else class="text-sm text-gray-400 italic">
                                Sin notas para esta edición.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- ─── Timeline ───────────────────────────────────────────── -->
                <div v-if="isEmpty && !showNewDayForm" class="text-center py-16 text-gray-400">
                    <p class="text-2xl mb-1">🗓️</p>
                    <p class="text-base">Todavía no hay días en el cronograma de esta edición.</p>
                    <button
                        v-if="canManageNow"
                        type="button"
                        class="mt-4 text-sm text-ember hover:text-ember-strong"
                        @click="openNewDay"
                    >
                        + Agregar primer día
                    </button>
                </div>

                <div v-for="(day, dayIdx) in localDays" :key="day.id" class="space-y-1">

                    <!-- Day header ─────────────────────────────────────────── -->
                    <div class="flex items-start gap-2">
                        <!-- Reorder buttons -->
                        <div v-if="canManageNow" class="flex flex-col gap-0.5 mt-0.5 flex-shrink-0">
                            <button
                                type="button"
                                :disabled="dayIdx === 0"
                                class="text-gray-600 hover:text-gray-300 disabled:opacity-20 text-xs leading-none"
                                title="Subir"
                                @click="reorderDay(day.id, 'up')"
                            >▲</button>
                            <button
                                type="button"
                                :disabled="dayIdx === localDays.length - 1"
                                class="text-gray-600 hover:text-gray-300 disabled:opacity-20 text-xs leading-none"
                                title="Bajar"
                                @click="reorderDay(day.id, 'down')"
                            >▼</button>
                        </div>

                        <!-- Day info + actions -->
                        <div class="flex-1 min-w-0">
                            <!-- Edit day form -->
                            <form
                                v-if="editingDayId === day.id"
                                @submit.prevent="submitDay"
                                class="bg-indigo-50 rounded-xl p-4 space-y-3 mb-2"
                            >
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Fecha *</label>
                                        <input
                                            v-model="dayForm.date"
                                            type="date"
                                            required
                                            class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                        />
                                        <p v-if="dayForm.errors.date" class="text-xs text-red-600 mt-1">{{ dayForm.errors.date }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Título</label>
                                        <input
                                            v-model="dayForm.title"
                                            type="text"
                                            placeholder="Ej: Día del Locro"
                                            class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Descripción</label>
                                    <textarea
                                        v-model="dayForm.description"
                                        rows="2"
                                        class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                    />
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" :disabled="dayForm.processing"
                                        class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-500 disabled:opacity-50">
                                        Guardar
                                    </button>
                                    <button type="button" class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900"
                                        @click="editingDayId = null">
                                        Cancelar
                                    </button>
                                </div>
                            </form>

                            <!-- Day label -->
                            <div v-else class="flex items-center flex-wrap gap-x-3 gap-y-1 mb-2">
                                <span class="text-lg font-bold text-white uppercase tracking-wide">
                                    {{ fmtDate(day.date) }}
                                </span>
                                <span v-if="day.title" class="text-base font-medium text-gray-300">
                                    · {{ day.title }}
                                </span>
                                <p v-if="day.description" class="w-full text-sm text-gray-400 mt-0.5">
                                    {{ day.description }}
                                </p>
                                <!-- Day actions -->
                                <div v-if="canManageNow" class="flex items-center gap-3 ml-auto">
                                    <button type="button"
                                        class="text-xs text-ember hover:text-ember-strong"
                                        @click="openNewActivity(day.id)">
                                        + Actividad
                                    </button>
                                    <button type="button"
                                        class="text-xs text-gray-400 hover:text-white"
                                        @click="openEditDay(day)">
                                        Editar
                                    </button>
                                    <button type="button"
                                        class="text-xs text-red-400 hover:text-red-300"
                                        @click="deleteDay(day.id)">
                                        Eliminar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Activities ───────────────────────────────────────── -->
                    <div class="pl-6 space-y-1">
                        <div
                            v-for="(act, actIdx) in day.activities"
                            :key="act.id"
                            class="group"
                        >
                            <!-- Edit activity form -->
                            <form
                                v-if="editingActId === act.id && newActForDayId === day.id"
                                @submit.prevent="submitActivity(day.id)"
                                class="bg-gray-50 rounded-xl border border-gray-200 p-4 space-y-3"
                            >
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Título *</label>
                                    <input v-model="actForm.title" type="text" required
                                        class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                                    <p v-if="actForm.errors?.title" class="text-xs text-red-600 mt-1">{{ actForm.errors.title }}</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Instrucciones</label>
                                    <textarea v-model="actForm.description" rows="2"
                                        class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                                </div>

                                <!-- Time block -->
                                <div>
                                    <button v-if="!showTimeFields" type="button"
                                        class="text-xs text-indigo-600 hover:text-indigo-800"
                                        @click="showTimeFields = true">
                                        + Agregar horario
                                    </button>
                                    <div v-else class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Hora inicio</label>
                                            <input v-model="actForm.start_time" type="time"
                                                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-gray-700 mb-1">Hora fin (opcional)</label>
                                            <input v-model="actForm.end_time" type="time"
                                                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                                            <p v-if="actForm.errors?.end_time" class="text-xs text-red-600 mt-1">{{ actForm.errors.end_time }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Equipo responsable (opcional)</label>
                                    <select v-model="actForm.team"
                                        class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                                        <option value="">Sin equipo asignado</option>
                                        <option v-for="t in teams" :key="t" :value="t">{{ TEAM_LABELS[t] ?? t }}</option>
                                    </select>
                                </div>

                                <div class="flex gap-2">
                                    <button type="submit"
                                        class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-500">
                                        Guardar
                                    </button>
                                    <button type="button" class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900"
                                        @click="editingActId = null; newActForDayId = null">
                                        Cancelar
                                    </button>
                                </div>
                            </form>

                            <!-- Edit execution form (real date/time + observation) -->
                            <form
                                v-else-if="editingExecId === act.id"
                                @submit.prevent="submitExecution(day.id, act.id)"
                                class="bg-amber-50 rounded-xl border border-amber-100 p-4 space-y-3"
                            >
                                <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide">
                                    Ejecución real · {{ act.title }}
                                </p>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Fecha real (opcional)</label>
                                        <input v-model="execForm.actual_date" type="date"
                                            class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                                        <p v-if="execForm.errors?.actual_date" class="text-xs text-red-600 mt-1">{{ execForm.errors.actual_date }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Hora real (opcional)</label>
                                        <input v-model="execForm.actual_time" type="time"
                                            :disabled="!execForm.actual_date"
                                            class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500 disabled:bg-gray-100" />
                                        <p v-if="execForm.errors?.actual_time" class="text-xs text-red-600 mt-1">{{ execForm.errors.actual_time }}</p>
                                    </div>
                                </div>
                                <p class="text-xs text-gray-500">
                                    Podés cargar solo la fecha si no recordás la hora exacta.
                                </p>
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Observación posterior (opcional)</label>
                                    <textarea v-model="execForm.notes" rows="2"
                                        placeholder="Qué ocurrió, problemas, sugerencias para la próxima edición…"
                                        class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <button type="submit"
                                        class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-500">
                                        Guardar
                                    </button>
                                    <button type="button" class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900"
                                        @click="editingExecId = null">
                                        Cancelar
                                    </button>
                                    <button type="button" class="px-3 py-1.5 text-sm text-gray-400 hover:text-gray-600 sm:ml-auto"
                                        @click="resetActivity(day.id, act.id)">
                                        Volver a pendiente
                                    </button>
                                </div>
                            </form>

                            <!-- Activity row (view) -->
                            <div
                                v-else
                                class="flex items-start gap-2 py-2 border-b border-border"
                                :class="{ 'border-b-0': actIdx === day.activities.length - 1 }"
                            >
                                <!-- Reorder buttons: only meaningful for activities without a fixed start_time -->
                                <div v-if="canManageNow && !act.start_time" class="flex flex-col gap-0.5 mt-0.5 flex-shrink-0 w-3">
                                    <button type="button" :disabled="isFirstUntimed(day, act)"
                                        class="text-gray-700 hover:text-gray-400 disabled:opacity-20 text-xs leading-none group-hover:text-gray-500"
                                        @click="reorderActivity(day.id, act.id, 'up')">▲</button>
                                    <button type="button" :disabled="isLastUntimed(day, act)"
                                        class="text-gray-700 hover:text-gray-400 disabled:opacity-20 text-xs leading-none group-hover:text-gray-500"
                                        @click="reorderActivity(day.id, act.id, 'down')">▼</button>
                                </div>
                                <div v-else-if="canManageNow" class="w-3 flex-shrink-0"></div>

                                <!-- Time column -->
                                <div class="w-24 flex-shrink-0 text-xs text-gray-400 font-mono pt-1 text-right">
                                    <span v-if="act.start_time">{{ fmtTime(act.start_time) }}</span>
                                    <span v-if="act.start_time && act.end_time">–{{ fmtTime(act.end_time) }}</span>
                                </div>

                                <!-- Status icon -->
                                <span class="flex-shrink-0 pt-0.5 font-mono text-base" :class="statusClass(act.status)">
                                    {{ statusIcon(act.status) }}
                                </span>

                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium leading-snug" :class="titleClass(act.status)">
                                        {{ act.title }}
                                        <span v-if="act.team" class="ml-2 text-xs font-normal text-indigo-500">
                                            [{{ TEAM_LABELS[act.team] ?? act.team }}]
                                        </span>
                                    </p>
                                    <p v-if="act.description" class="text-xs text-gray-400 mt-0.5 whitespace-pre-line">
                                        {{ act.description }}
                                    </p>

                                    <!-- Completion info: "Previsto 18:00 · Real 18:25 · +25 min" cuando hay datos suficientes -->
                                    <div v-if="act.status === 'completed'" class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                                        <span v-if="act.start_time" class="text-gray-400">Previsto {{ fmtTime(act.start_time) }}</span>
                                        <span v-if="act.start_time && (act.actual_date || act.actual_time)" class="text-gray-400">·</span>
                                        <span v-if="act.actual_date && act.actual_time" class="text-green-400">
                                            Real <template v-if="!isSameDay(day, act)">{{ fmtShortDate(act.actual_date) }} </template>{{ act.actual_time }}
                                        </span>
                                        <span v-else-if="act.actual_date" class="text-green-400">
                                            Real {{ fmtShortDate(act.actual_date) }}
                                        </span>
                                        <span v-else class="text-green-500">Realizada</span>
                                        <template v-if="fmtDiff(day, act)">
                                            <span class="text-gray-400">·</span>
                                            <span class="font-medium" :class="diffClass(fmtDiff(day, act))">
                                                {{ fmtDiff(day, act) }}
                                            </span>
                                        </template>
                                    </div>
                                    <p v-if="act.status === 'skipped'" class="text-xs text-gray-400 mt-0.5">Omitida</p>

                                    <!-- Observation -->
                                    <p v-if="act.notes" class="text-xs text-amber-400 italic mt-1 border-l-2 border-amber-700 pl-2">
                                        {{ act.notes }}
                                    </p>

                                    <!-- Action buttons: las principales (realizar) llevan color/peso;
                                         las secundarias (omitir/editar/eliminar) quedan discretas. -->
                                    <div v-if="canManageNow" class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5">
                                        <template v-if="act.status === 'pending'">
                                            <button type="button"
                                                class="text-xs font-medium text-green-500 hover:text-green-400"
                                                @click="markDone(day.id, act.id)">
                                                Marcar realizada
                                            </button>
                                            <button type="button"
                                                class="text-xs font-medium text-blue-500 hover:text-blue-400"
                                                @click="completeNow(day.id, act.id)">
                                                Completar ahora
                                            </button>
                                            <button type="button"
                                                class="text-xs text-gray-300 hover:text-white"
                                                @click="skipActivity(day.id, act.id)">
                                                Omitir
                                            </button>
                                        </template>
                                        <template v-else-if="act.status === 'completed'">
                                            <button type="button"
                                                class="text-xs text-ember hover:text-ember-strong"
                                                @click="openEditExecution(act)">
                                                Editar ejecución
                                            </button>
                                            <button type="button"
                                                class="text-xs text-gray-300 hover:text-white"
                                                @click="resetActivity(day.id, act.id)">
                                                Volver a pendiente
                                            </button>
                                        </template>
                                        <template v-else>
                                            <button type="button"
                                                class="text-xs text-gray-300 hover:text-white"
                                                @click="resetActivity(day.id, act.id)">
                                                Volver a pendiente
                                            </button>
                                        </template>
                                        <span class="text-gray-600">|</span>
                                        <button type="button"
                                            class="text-xs text-gray-300 hover:text-white"
                                            @click="openEditActivity(act)">
                                            Editar
                                        </button>
                                        <button type="button"
                                            class="text-xs text-gray-300 hover:text-red-500"
                                            @click="deleteActivity(day.id, act.id)">
                                            Eliminar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- New activity form -->
                        <form
                            v-if="newActForDayId === day.id && editingActId === null"
                            @submit.prevent="submitActivity(day.id)"
                            class="bg-gray-50 rounded-xl border border-gray-200 p-4 space-y-3"
                        >
                            <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Nueva actividad</p>
                            <div>
                                <input v-model="actForm.title" type="text" required placeholder="Título *"
                                    class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                                <p v-if="actForm.errors?.title" class="text-xs text-red-600 mt-1">{{ actForm.errors.title }}</p>
                            </div>
                            <textarea v-model="actForm.description" rows="2" placeholder="Instrucciones (opcional)"
                                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />

                            <div>
                                <button v-if="!showTimeFields" type="button"
                                    class="text-xs text-indigo-600 hover:text-indigo-800"
                                    @click="showTimeFields = true">
                                    + Agregar horario
                                </button>
                                <div v-else class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Hora inicio</label>
                                        <input v-model="actForm.start_time" type="time"
                                            class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Hora fin (opcional)</label>
                                        <input v-model="actForm.end_time" type="time"
                                            class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                                        <p v-if="actForm.errors?.end_time" class="text-xs text-red-600 mt-1">{{ actForm.errors.end_time }}</p>
                                    </div>
                                </div>
                            </div>

                            <select v-model="actForm.team"
                                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="">Sin equipo asignado</option>
                                <option v-for="t in teams" :key="t" :value="t">{{ TEAM_LABELS[t] ?? t }}</option>
                            </select>

                            <div class="flex gap-2">
                                <button type="submit"
                                    class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-500">
                                    Agregar
                                </button>
                                <button type="button" class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900"
                                    @click="newActForDayId = null; showTimeFields = false">
                                    Cancelar
                                </button>
                            </div>
                        </form>

                        <!-- Placeholder when no activities -->
                        <p
                            v-if="day.activities.length === 0 && newActForDayId !== day.id"
                            class="text-xs text-gray-400 italic pl-[9.5rem]"
                        >
                            Sin actividades.
                            <button v-if="canManageNow" type="button"
                                class="ml-1 text-ember hover:text-ember-strong not-italic"
                                @click="openNewActivity(day.id)">
                                + Agregar
                            </button>
                        </p>
                    </div>

                    <!-- Divider between days -->
                    <div v-if="dayIdx < localDays.length - 1" class="border-t border-border mt-4" />
                </div>

                <!-- ─── New day form ────────────────────────────────────── -->
                <form
                    v-if="showNewDayForm"
                    @submit.prevent="submitDay"
                    class="bg-indigo-50 rounded-xl border border-indigo-100 p-4 space-y-3"
                >
                    <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide">Nuevo día</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Fecha *</label>
                            <input v-model="dayForm.date" type="date" required
                                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                            <p v-if="dayForm.errors.date" class="text-xs text-red-600 mt-1">{{ dayForm.errors.date }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Título</label>
                            <input v-model="dayForm.title" type="text" placeholder="Ej: Día previo"
                                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Descripción</label>
                        <textarea v-model="dayForm.description" rows="2"
                            class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" :disabled="dayForm.processing"
                            class="px-3 py-1.5 bg-green-600 text-white text-sm rounded-lg hover:bg-green-500 disabled:opacity-50">
                            Crear día
                        </button>
                        <button type="button" class="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900"
                            @click="showNewDayForm = false">
                            Cancelar
                        </button>
                    </div>
                </form>

                <!-- ─── Add day button ──────────────────────────────────── -->
                <div v-if="canManageNow && !showNewDayForm" class="text-center pt-2">
                    <button
                        type="button"
                        class="text-sm text-ember hover:text-ember-strong"
                        @click="openNewDay"
                    >
                        + Agregar día
                    </button>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
