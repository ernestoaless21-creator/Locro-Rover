<script setup>
/**
 * Busca un cliente existente por nombre/apellido/telefono (debounced) o
 * permite crear uno nuevo sin salir de la pantalla de alta de pedido
 * (abre ClientFormModal, y al guardar lo selecciona automaticamente).
 */
import { ref, watch } from 'vue'
import axios from 'axios'
import ClientFormModal from '@/Components/ClientFormModal.vue'

const props = defineProps({
  modelValue: { type: Object, default: null }, // cliente seleccionado {id, first_name, last_name, phone}
})

const emit = defineEmits(['update:modelValue'])

const query = ref('')
const results = ref([])
const searching = ref(false)
const showDropdown = ref(false)
const showCreateModal = ref(false)
let debounceTimer = null

watch(query, (value) => {
  clearTimeout(debounceTimer)
  if (!value || value.length < 2) {
    results.value = []
    return
  }
  debounceTimer = setTimeout(async () => {
    searching.value = true
    try {
      const { data } = await axios.get('/clients/search', { params: { q: value } })
      results.value = data
      showDropdown.value = true
    } finally {
      searching.value = false
    }
  }, 300)
})

function select(client) {
  emit('update:modelValue', client)
  showDropdown.value = false
  query.value = ''
}

function clearSelection() {
  emit('update:modelValue', null)
}

function onClientCreated(client) {
  select(client)
  showCreateModal.value = false
}
</script>

<template>
  <div>
    <div v-if="modelValue" class="flex items-center justify-between bg-gray-800 rounded-md px-3 py-2 text-sm">
      <span>{{ modelValue.first_name }} {{ modelValue.last_name }} — {{ modelValue.phone }}</span>
      <button type="button" class="text-red-400 hover:text-red-300 text-xs" @click="clearSelection">
        Cambiar
      </button>
    </div>

    <div v-else class="relative">
      <div class="flex gap-2">
        <input
          v-model="query"
          type="text"
          placeholder="Buscar cliente por nombre, apellido o telefono..."
          class="flex-1 bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-sm"
          @focus="showDropdown = true"
        />
        <button
          type="button"
          class="bg-gray-700 hover:bg-gray-600 px-3 py-2 rounded-md text-sm whitespace-nowrap"
          @click="showCreateModal = true"
        >
          + Cliente nuevo
        </button>
      </div>

      <div
        v-if="showDropdown && (results.length || searching)"
        class="absolute z-10 mt-1 w-full bg-gray-800 border border-gray-700 rounded-md shadow-lg max-h-56 overflow-y-auto"
      >
        <div v-if="searching" class="px-3 py-2 text-xs text-gray-400">Buscando...</div>
        <button
          v-for="client in results"
          :key="client.id"
          type="button"
          class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-700"
          @click="select(client)"
        >
          {{ client.first_name }} {{ client.last_name }}
          <span class="text-gray-400 text-xs">— {{ client.phone || 'sin telefono' }}</span>
        </button>
        <div v-if="!searching && !results.length" class="px-3 py-2 text-xs text-gray-400">
          Sin resultados. Prueba "+ Cliente nuevo".
        </div>
      </div>
    </div>

    <ClientFormModal
      :show="showCreateModal"
      :client="null"
      @close="showCreateModal = false"
      @saved="onClientCreated"
    />
  </div>
</template>
