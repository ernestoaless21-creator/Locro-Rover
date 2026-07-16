<script setup>
import { ref } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    meeting:            Object,
    decisionCategories: Object,
    decisionTeams:      Array,
    availableDocuments: Array,
    canManage:          Boolean,
})

// ── Decisiones ────────────────────────────────────────────────────────────────

const showDecisionForm = ref(false)
const decisionForm = useForm({ text: '', category: '', team: '' })

function submitDecision() {
    decisionForm.post(route('meetings.decisions.store', props.meeting.id), {
        preserveScroll: true,
        onSuccess: () => {
            decisionForm.reset()
            showDecisionForm.value = false
        },
    })
}

const editingDecisionId = ref(null)
const editDecisionForm = useForm({ text: '', category: '', team: '' })

function startEditDecision(d) {
    editingDecisionId.value = d.id
    editDecisionForm.text     = d.text
    editDecisionForm.category = d.category
    editDecisionForm.team     = d.team ?? ''
}

function submitEditDecision(d) {
    editDecisionForm.put(route('meetings.decisions.update', [props.meeting.id, d.id]), {
        preserveScroll: true,
        onSuccess: () => { editingDecisionId.value = null },
    })
}

function deleteDecision(d) {
    if (!window.confirm('¿Eliminar este punto?')) return
    router.delete(route('meetings.decisions.destroy', [props.meeting.id, d.id]), { preserveScroll: true })
}

// ── Documentos ────────────────────────────────────────────────────────────────

const showDocForm = ref(false)
const docForm = useForm({ team_document_id: '' })

function attachDocument() {
    docForm.post(route('meetings.documents.attach', props.meeting.id), {
        preserveScroll: true,
        onSuccess: () => {
            docForm.reset()
            showDocForm.value = false
        },
    })
}

function detachDocument(doc) {
    if (!window.confirm('¿Desvincular este documento?')) return
    router.delete(route('meetings.documents.detach', [props.meeting.id, doc.id]), { preserveScroll: true })
}

// ── Acta ──────────────────────────────────────────────────────────────────────

function deleteMeeting() {
    if (!window.confirm('¿Eliminar este acta permanentemente?')) return
    router.delete(route('meetings.destroy', props.meeting.id))
}

// ── Helpers ───────────────────────────────────────────────────────────────────

const categoryColor = (cat) => ({
    decision:          'border-l-indigo-500',
    aspecto_positivo:  'border-l-green-500',
    aspecto_a_mejorar: 'border-l-yellow-500',
    leccion_aprendida: 'border-l-purple-500',
    pendiente:         'border-l-red-500',
})[cat] ?? 'border-l-gray-300'

const teamLabel = (slug) => ({
    logistica:       'Logística',
    compras:         'Compras',
    infraestructura: 'Infraestructura',
    publicidad:      'Publicidad',
})[slug] ?? slug

function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1048576) return `${(bytes / 1024).toFixed(0)} KB`
    return `${(bytes / 1048576).toFixed(1)} MB`
}

function formatDate(dateStr) {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString('es-AR', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
    })
}

const attachedIds = () => props.meeting.documents.map(d => d.id)
const availableToAttach = () => props.availableDocuments.filter(d => !attachedIds().includes(d.id))
</script>

<template>
    <AppLayout :title="meeting.title">
        <template #header>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <Link :href="route('meetings.index')" class="text-xs text-ember hover:text-ember-strong uppercase tracking-wide">&larr; Actas</Link>
                    <h2 class="mt-1 font-semibold text-xl text-white leading-tight">{{ meeting.title }}</h2>
                    <p class="text-sm text-gray-400 mt-0.5 capitalize">{{ formatDate(meeting.date) }}</p>
                </div>
                <div v-if="canManage" class="flex gap-2 shrink-0 mt-1">
                    <Link
                        :href="route('meetings.edit', meeting.id)"
                        class="px-3 py-1.5 text-sm text-gray-300 border border-gray-600 rounded-md hover:bg-surface-3 hover:text-white transition"
                    >
                        Editar
                    </Link>
                    <button
                        type="button"
                        class="px-3 py-1.5 text-sm border border-red-800 text-red-400 rounded-md hover:bg-ember-wash transition"
                        @click="deleteMeeting"
                    >
                        Eliminar
                    </button>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

                <!-- Encabezado formal del acta -->
                <div class="bg-white shadow-sm border border-gray-200 rounded-lg px-8 py-6">
                    <div v-if="meeting.secretary_name" class="text-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-0.5">Secretario/a</p>
                        <p class="text-gray-800 font-medium">{{ meeting.secretary_name }}</p>
                    </div>

                    <!-- Asistentes -->
                    <div v-if="meeting.presentes.length || meeting.ausentes.length || meeting.otros_asistentes" class="mt-6 pt-5 border-t border-gray-100 grid sm:grid-cols-2 gap-5 text-sm">
                        <div v-if="meeting.presentes.length">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">
                                Presentes <span class="text-gray-300">({{ meeting.presentes.length }})</span>
                            </p>
                            <ul class="space-y-0.5">
                                <li v-for="name in meeting.presentes" :key="name" class="text-gray-700 flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-400 inline-block shrink-0"></span>
                                    {{ name }}
                                </li>
                            </ul>
                        </div>
                        <div>
                            <div v-if="meeting.ausentes.length">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">
                                    Ausentes <span class="text-gray-300">({{ meeting.ausentes.length }})</span>
                                </p>
                                <ul class="space-y-0.5">
                                    <li v-for="name in meeting.ausentes" :key="name" class="text-gray-400 flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-300 inline-block shrink-0"></span>
                                        {{ name }}
                                    </li>
                                </ul>
                            </div>
                            <div v-if="meeting.otros_asistentes" :class="meeting.ausentes.length ? 'mt-4' : ''">
                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-1">Otros asistentes</p>
                                <p class="text-gray-600 text-sm">{{ meeting.otros_asistentes }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Desarrollo -->
                <div v-if="meeting.development" class="bg-white shadow-sm border border-gray-200 rounded-lg px-8 py-6">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Desarrollo</h3>
                    <p class="text-gray-800 text-sm leading-relaxed whitespace-pre-line">{{ meeting.development }}</p>
                </div>

                <!-- Decisiones: solo botón cuando está vacío, tarjeta completa cuando hay contenido -->
                <button
                    v-if="canManage && meeting.decisions.length === 0 && !showDecisionForm"
                    type="button"
                    class="text-sm text-indigo-600 hover:text-indigo-800"
                    @click="showDecisionForm = true"
                >
                    + Agregar decisión
                </button>

                <div v-if="meeting.decisions.length > 0 || showDecisionForm" class="bg-white shadow-sm border border-gray-200 rounded-lg px-8 py-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Decisiones</h3>
                        <button
                            v-if="canManage"
                            type="button"
                            class="text-sm text-indigo-600 hover:text-indigo-800"
                            @click="showDecisionForm = !showDecisionForm"
                        >
                            + Agregar decisión
                        </button>
                    </div>

                    <form v-if="showDecisionForm && canManage" class="mb-5 p-4 bg-gray-50 rounded-md space-y-3" @submit.prevent="submitDecision">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Categoría</label>
                                <select v-model="decisionForm.category" class="w-full border-gray-300 rounded text-sm">
                                    <option value="" disabled>Seleccionar...</option>
                                    <option v-for="(label, key) in decisionCategories" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <p v-if="decisionForm.errors.category" class="mt-0.5 text-xs text-red-600">{{ decisionForm.errors.category }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Equipo (opcional)</label>
                                <select v-model="decisionForm.team" class="w-full border-gray-300 rounded text-sm">
                                    <option value="">Sin equipo específico</option>
                                    <option v-for="t in decisionTeams" :key="t" :value="t">{{ teamLabel(t) }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <textarea
                                v-model="decisionForm.text"
                                rows="3"
                                placeholder="Descripción de la decisión..."
                                class="w-full border-gray-300 rounded text-sm"
                            />
                            <p v-if="decisionForm.errors.text" class="mt-0.5 text-xs text-red-600">{{ decisionForm.errors.text }}</p>
                        </div>
                        <div class="flex justify-end gap-2">
                            <button type="button" class="text-sm text-gray-500 hover:text-gray-700" @click="showDecisionForm = false">Cancelar</button>
                            <button type="submit" :disabled="decisionForm.processing" class="px-3 py-1.5 bg-green-600 text-white text-sm rounded hover:bg-green-500 disabled:opacity-50">Agregar</button>
                        </div>
                    </form>

                    <ul class="space-y-3">
                        <li
                            v-for="d in meeting.decisions"
                            :key="d.id"
                            class="border-l-4 pl-4 py-2"
                            :class="categoryColor(d.category)"
                        >
                            <div v-if="editingDecisionId !== d.id">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1">
                                        <span class="inline-block text-xs font-semibold px-2 py-0.5 bg-gray-100 text-gray-500 rounded mb-1">
                                            {{ decisionCategories[d.category] ?? d.category }}
                                            <span v-if="d.team"> · {{ teamLabel(d.team) }}</span>
                                        </span>
                                        <p class="text-sm text-gray-800 whitespace-pre-line">{{ d.text }}</p>
                                    </div>
                                    <div v-if="canManage" class="flex gap-2 shrink-0">
                                        <button type="button" class="text-xs text-gray-400 hover:text-indigo-600" @click="startEditDecision(d)">Editar</button>
                                        <button type="button" class="text-xs text-gray-400 hover:text-red-600" @click="deleteDecision(d)">Eliminar</button>
                                    </div>
                                </div>
                            </div>

                            <form v-else class="space-y-2" @submit.prevent="submitEditDecision(d)">
                                <div class="grid grid-cols-2 gap-2">
                                    <select v-model="editDecisionForm.category" class="border-gray-300 rounded text-sm">
                                        <option v-for="(label, key) in decisionCategories" :key="key" :value="key">{{ label }}</option>
                                    </select>
                                    <select v-model="editDecisionForm.team" class="border-gray-300 rounded text-sm">
                                        <option value="">Sin equipo</option>
                                        <option v-for="t in decisionTeams" :key="t" :value="t">{{ teamLabel(t) }}</option>
                                    </select>
                                </div>
                                <textarea v-model="editDecisionForm.text" rows="2" class="w-full border-gray-300 rounded text-sm" />
                                <div class="flex gap-2 justify-end">
                                    <button type="button" class="text-xs text-gray-500" @click="editingDecisionId = null">Cancelar</button>
                                    <button type="submit" :disabled="editDecisionForm.processing" class="text-xs px-2 py-1 bg-green-600 text-white rounded">Guardar</button>
                                </div>
                            </form>
                        </li>
                    </ul>
                </div>

                <!-- Documentos asociados -->
                <div class="bg-white shadow-sm border border-gray-200 rounded-lg px-8 py-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">Documentos asociados</h3>
                        <button
                            v-if="canManage && availableToAttach().length > 0"
                            type="button"
                            class="text-sm text-indigo-600 hover:text-indigo-800"
                            @click="showDocForm = !showDocForm"
                        >
                            + Asociar documento
                        </button>
                    </div>

                    <form v-if="showDocForm && canManage" class="mb-4 p-4 bg-gray-50 rounded-md flex gap-2 items-end" @submit.prevent="attachDocument">
                        <div class="flex-1">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Documento</label>
                            <select v-model="docForm.team_document_id" class="w-full border-gray-300 rounded text-sm">
                                <option value="" disabled>Seleccionar...</option>
                                <option v-for="doc in availableToAttach()" :key="doc.id" :value="doc.id">
                                    [{{ teamLabel(doc.team) }}] {{ doc.name }}
                                </option>
                            </select>
                        </div>
                        <button type="submit" :disabled="docForm.processing" class="px-3 py-1.5 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-700 disabled:opacity-50">Asociar</button>
                        <button type="button" class="px-3 py-1.5 text-sm text-gray-500 hover:text-gray-700" @click="showDocForm = false">Cancelar</button>
                    </form>

                    <div v-if="meeting.documents.length === 0 && !showDocForm" class="text-sm text-gray-400 italic">
                        Sin documentos asociados.
                    </div>

                    <ul class="space-y-2">
                        <li
                            v-for="doc in meeting.documents"
                            :key="doc.id"
                            class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0"
                        >
                            <div>
                                <a
                                    :href="route('teams.documents.download', [doc.team, doc.id])"
                                    class="text-sm text-indigo-600 hover:text-indigo-800 font-medium"
                                >
                                    {{ doc.name }}
                                </a>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    {{ teamLabel(doc.team) }} &middot; {{ doc.file_name }} &middot; {{ formatBytes(doc.file_size) }}
                                    <span v-if="doc.uploader"> &middot; {{ doc.uploader.name }}</span>
                                </p>
                            </div>
                            <button
                                v-if="canManage"
                                type="button"
                                class="text-xs text-gray-400 hover:text-red-600 ml-4"
                                @click="detachDocument(doc)"
                            >
                                Desvincular
                            </button>
                        </li>
                    </ul>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
