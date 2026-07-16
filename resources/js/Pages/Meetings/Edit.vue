<script setup>
import { reactive, ref, computed } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    meeting:     Object,
    activeUsers: Array,
})

const form = useForm({
    title:            props.meeting.title,
    date:             props.meeting.date,
    development:      props.meeting.development ?? '',
    secretary_id:     props.meeting.secretary_id ?? null,
    otros_asistentes: props.meeting.otros_asistentes ?? '',
    attendances:      [],
})

// Inicializar mapa desde datos existentes + completar con usuarios activos no incluidos
const existingMap = Object.fromEntries(
    (props.meeting.attendances ?? []).map(a => [a.user_id, a.is_present])
)

const attendanceMap = reactive(
    Object.fromEntries(
        props.activeUsers.map(u => [
            u.id,
            // Usar el valor guardado si existe, si no: ausente por defecto
            u.id in existingMap ? existingMap[u.id] : false,
        ])
    )
)

const attendanceSearch = ref('')

const filteredUsers = computed(() => {
    const q = attendanceSearch.value.trim().toLowerCase()
    if (!q) return props.activeUsers
    return props.activeUsers.filter(u => u.name.toLowerCase().includes(q))
})

const presentCount = computed(() =>
    Object.values(attendanceMap).filter(Boolean).length
)

const absentCount = computed(() =>
    props.activeUsers.length - presentCount.value
)

function markAll(value) {
    for (const u of props.activeUsers) {
        attendanceMap[u.id] = value
    }
}

function submit() {
    form.attendances = Object.entries(attendanceMap).map(([userId, isPresent]) => ({
        user_id:    Number(userId),
        is_present: isPresent,
    }))
    form.put(route('meetings.update', props.meeting.id))
}
</script>

<template>
    <AppLayout title="Editar acta">
        <template #header>
            <h2 class="font-semibold text-xl text-white leading-tight">Editar acta</h2>
        </template>

        <div class="py-8">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <form
                    class="bg-white shadow-sm border border-gray-200 rounded-lg divide-y divide-gray-100"
                    @submit.prevent="submit"
                >

                    <!-- Encabezado -->
                    <div class="px-8 py-6 space-y-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Título</label>
                            <input
                                v-model="form.title"
                                type="text"
                                class="w-full border-0 border-b border-gray-300 rounded-none text-lg font-medium text-gray-900 focus:ring-0 focus:border-indigo-500 px-0 pb-1"
                            >
                            <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
                        </div>

                        <div class="flex gap-6">
                            <div class="flex-1">
                                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Fecha</label>
                                <input
                                    v-model="form.date"
                                    type="date"
                                    class="border-gray-300 rounded-md shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                <p v-if="form.errors.date" class="mt-1 text-xs text-red-600">{{ form.errors.date }}</p>
                            </div>
                            <div class="flex-1">
                                <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Secretario/a</label>
                                <select
                                    v-model="form.secretary_id"
                                    class="w-full border-gray-300 rounded-md shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                >
                                    <option :value="null">— Sin asignar —</option>
                                    <option v-for="u in activeUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Asistentes -->
                    <div class="px-8 py-6 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nómina de asistentes</h3>
                                <p class="mt-0.5 text-xs text-gray-400">
                                    {{ presentCount }} presentes · {{ absentCount }} ausentes
                                    <template v-if="form.otros_asistentes?.trim()"> · con invitados externos</template>
                                </p>
                            </div>
                            <button type="button" class="text-xs text-gray-400 hover:text-indigo-600" @click="markAll(true)">
                                Marcar todos presentes
                            </button>
                        </div>

                        <div v-if="activeUsers.length > 6" class="relative">
                            <input
                                v-model="attendanceSearch"
                                type="text"
                                placeholder="Buscar Rover..."
                                class="w-full pl-8 border-gray-300 rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                            >
                            <svg class="absolute left-2.5 top-2.5 size-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                            </svg>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-y-2 gap-x-4 max-h-52 overflow-y-auto pr-1">
                            <label
                                v-for="u in filteredUsers"
                                :key="u.id"
                                class="flex items-center gap-2 cursor-pointer group"
                            >
                                <input
                                    v-model="attendanceMap[u.id]"
                                    type="checkbox"
                                    :true-value="true"
                                    :false-value="false"
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                >
                                <span class="text-sm text-gray-700 group-hover:text-gray-900 truncate">{{ u.name }}</span>
                            </label>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Otros asistentes</label>
                            <input
                                v-model="form.otros_asistentes"
                                type="text"
                                placeholder="Invitados o personas externas al sistema..."
                                class="w-full border-gray-300 rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                            >
                        </div>
                    </div>

                    <!-- Desarrollo -->
                    <div class="px-8 py-6 space-y-2">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Desarrollo</h3>
                        <textarea
                            v-model="form.development"
                            rows="8"
                            class="w-full border-gray-300 rounded-md text-sm text-gray-800 leading-relaxed focus:ring-indigo-500 focus:border-indigo-500"
                        />
                    </div>

                    <!-- Acciones -->
                    <div class="px-8 py-4 bg-gray-50 rounded-b-lg flex justify-end gap-3">
                        <a :href="route('meetings.show', meeting.id)" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900">Cancelar</a>
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-6 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 disabled:opacity-50 transition"
                        >
                            Guardar cambios
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </AppLayout>
</template>
