<script setup>
import { ref, reactive } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  team:      { type: String,  required: true },
  suppliers: { type: Array,   required: true },
  canManage: { type: Boolean, required: true },
})

const toast = useToast()

const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 })
function fmtMoney(v) {
  return v === null || v === undefined ? '—' : money.format(Number(v))
}

// ─── Nuevo proveedor ─────────────────────────────────────────────────────────
const showNewForm = ref(false)
const newForm = useForm({ name: '', phone: '', address: '', notes: '' })

function submitNew() {
  if (!newForm.name.trim()) return
  newForm.post(route('suppliers.store', { team: props.team }), {
    preserveScroll: true,
    onSuccess: () => { showNewForm.value = false; newForm.reset() },
    onError: () => toast.error('Error al crear el proveedor.'),
  })
}

// ─── Editar proveedor ────────────────────────────────────────────────────────
const editingId = ref(null)
const editForm = reactive({ name: '', phone: '', address: '', notes: '', is_active: true })

function startEdit(s) {
  editingId.value = s.id
  editForm.name = s.name
  editForm.phone = s.phone ?? ''
  editForm.address = s.address ?? ''
  editForm.notes = s.notes ?? ''
  editForm.is_active = s.is_active
}

function cancelEdit() {
  editingId.value = null
}

function saveEdit(s) {
  if (!editForm.name.trim()) return
  router.put(route('suppliers.update', { team: props.team, supplier: s.id }), { ...editForm }, {
    preserveScroll: true,
    onSuccess: () => cancelEdit(),
    onError: () => toast.error('Error al actualizar el proveedor.'),
  })
}

function toggleActive(s) {
  router.put(route('suppliers.update', { team: props.team, supplier: s.id }), {
    name: s.name, phone: s.phone, address: s.address, notes: s.notes, is_active: !s.is_active,
  }, { preserveScroll: true })
}
</script>

<template>
  <Head title="Proveedores" />
  <AppLayout title="Proveedores">
    <template #header>
      <div class="flex items-center gap-4">
        <a :href="route('teams.show', team)" class="text-xs text-ember hover:text-ember-strong uppercase tracking-wide">
          ← Compras
        </a>
        <h2 class="font-semibold text-xl text-white leading-tight">Proveedores</h2>
      </div>
    </template>

    <div class="py-8 max-w-3xl mx-auto px-4">
      <p class="text-sm text-gray-500 mb-6">
        Lugares de compra reutilizables entre ediciones. El historial de qué se les compró vive en la
        planificación de compras de cada edición.
      </p>

      <!-- Nuevo proveedor -->
      <div v-if="canManage" class="mb-6">
        <button v-if="!showNewForm" type="button" class="text-sm text-indigo-600 hover:text-indigo-800" @click="showNewForm = true">
          + Agregar proveedor
        </button>
        <form v-else @submit.prevent="submitNew" class="bg-white rounded-lg shadow-sm border border-gray-100 p-4 space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Nombre *</label>
              <input v-model="newForm.name" type="text" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
              <p v-if="newForm.errors.name" class="text-xs text-red-600 mt-1">{{ newForm.errors.name }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Teléfono (opcional)</label>
              <input v-model="newForm.phone" type="text" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Dirección o zona (opcional)</label>
            <input v-model="newForm.address" type="text" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Observaciones (opcional)</label>
            <textarea v-model="newForm.notes" rows="2" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
          </div>
          <div class="flex gap-2">
            <button type="submit" :disabled="newForm.processing" class="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-500 disabled:opacity-50">
              Crear
            </button>
            <button type="button" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900" @click="showNewForm = false; newForm.reset()">
              Cancelar
            </button>
          </div>
        </form>
      </div>

      <!-- Lista -->
      <div v-if="suppliers.length" class="space-y-3">
        <div v-for="s in suppliers" :key="s.id" class="bg-white rounded-lg shadow-sm border border-gray-100 p-4"
          :class="{ 'opacity-50': !s.is_active }">

          <template v-if="editingId !== s.id">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-800">
                  {{ s.name }}
                  <span v-if="!s.is_active" class="ml-1 text-xs text-gray-400 font-normal">(inactivo)</span>
                </p>
                <p v-if="s.phone || s.address" class="text-xs text-gray-500 mt-0.5">
                  <span v-if="s.phone">{{ s.phone }}</span>
                  <span v-if="s.phone && s.address"> · </span>
                  <span v-if="s.address">{{ s.address }}</span>
                </p>
                <p v-if="s.notes" class="text-sm text-gray-600 mt-2 whitespace-pre-wrap leading-relaxed">{{ s.notes }}</p>
                <p class="text-xs text-gray-400 mt-2">
                  {{ s.purchase_count }} compra{{ s.purchase_count === 1 ? '' : 's' }} real{{ s.purchase_count === 1 ? '' : 'es' }} registrada{{ s.purchase_count === 1 ? '' : 's' }}
                  <template v-if="s.purchase_count > 0"> · {{ fmtMoney(s.total_spent) }} en total</template>
                </p>
              </div>
              <div v-if="canManage" class="flex items-center gap-2 shrink-0 text-xs">
                <button type="button" class="text-gray-400 hover:text-indigo-600" @click="startEdit(s)">Editar</button>
                <span class="text-gray-200">|</span>
                <button type="button" class="text-gray-400 hover:text-gray-600" @click="toggleActive(s)">
                  {{ s.is_active ? 'Desactivar' : 'Activar' }}
                </button>
              </div>
            </div>
          </template>

          <template v-else>
            <div class="space-y-3">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-xs font-medium text-gray-700 mb-1">Nombre *</label>
                  <input v-model="editForm.name" type="text" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-700 mb-1">Teléfono</label>
                  <input v-model="editForm.phone" type="text" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
                </div>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Dirección o zona</label>
                <input v-model="editForm.address" type="text" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Observaciones</label>
                <textarea v-model="editForm.notes" rows="2" class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
              </div>
              <div class="flex gap-2">
                <button type="button" class="px-3 py-1.5 bg-green-600 text-white text-xs rounded-lg hover:bg-green-500" @click="saveEdit(s)">
                  Guardar
                </button>
                <button type="button" class="px-3 py-1.5 text-xs text-gray-600 hover:text-gray-900" @click="cancelEdit">
                  Cancelar
                </button>
              </div>
            </div>
          </template>
        </div>
      </div>

      <div v-else class="text-center text-gray-400 py-12 text-sm">
        <p class="text-2xl mb-1">🚚</p>
        <p>Todavía no hay proveedores registrados.</p>
      </div>
    </div>
  </AppLayout>
</template>
