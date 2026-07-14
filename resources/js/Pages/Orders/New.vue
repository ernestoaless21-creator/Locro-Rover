<script setup>
/**
 * FASE 5C: rediseño del alta de pedido para una carga mucho mas rapida.
 * - Cantidad de porciones con control compacto (-/+).
 * - Salsas ya NO se muestran aca (se calculan igual en backend via
 *   PricingService; se ven en el listado/detalle, no en el formulario).
 * - "Retira en mano" invertido a un checkbox "Es delivery" (por defecto
 *   desmarcado = retira en mano). Si se marca, pide direccion de entrega,
 *   precargada desde el cliente pero editable y guardada como instantanea
 *   propia del pedido (no pisa la direccion permanente del cliente).
 * - Observaciones SIEMPRE visibles (ya no estan en "Opciones avanzadas").
 * - "Opciones avanzadas" ahora solo tiene precio excepcional (regalos tienen
 *   su propio modulo independiente, ver Gift), y solo se muestra si el
 *   usuario tiene el permiso 'pedidos.precio-excepcional'.
 */
import { Head, router } from '@inertiajs/vue3'
import { ref, computed, watch, onMounted } from 'vue'
import axios from 'axios'
import AppLayout from '@/Layouts/AppLayout.vue'
import ClientPicker from '@/Components/ClientPicker.vue'
import OrderLineForm from '@/Components/OrderLineForm.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  years: { type: Array, required: true },
  rovers: { type: Array, default: () => [] },
  canAssignRover: { type: Boolean, default: false },
  canExceptionalPrice: { type: Boolean, default: false },
  preselectedClient: { type: Object, default: null },
  preselectedYearId: { type: Number, default: null },
})

const toast = useToast()

const client = ref(props.preselectedClient ?? null)
const yearId = ref(props.preselectedYearId ?? props.years.find((y) => y.is_active)?.id ?? props.years[0]?.id ?? null)
const roverId = ref(null)
const isDelivery = ref(false) // por defecto: retira en mano
const deliveryAddress = ref('')
const observations = ref('')
const portions = ref(1)
const advancedItems = ref([])
const showAdvanced = ref(false)
const saving = ref(false)
const showConfirm = ref(false)

// Al elegir cliente, si ya esta marcado "Es delivery", precargar su
// direccion permanente (aunque este vacia). Sigue siendo editable para este
// pedido puntual sin modificar la direccion del cliente.
watch(client, (value) => {
  if (isDelivery.value) {
    deliveryAddress.value = value?.address ?? ''
  }
})

// Si se marca "Es delivery" y todavia no hay nada cargado, precargar desde
// el cliente ya seleccionado.
watch(isDelivery, (value) => {
  if (value && !deliveryAddress.value) {
    deliveryAddress.value = client.value?.address ?? ''
  }
})

function incPortions() {
  portions.value = (Number(portions.value) || 0) + 1
}
function decPortions() {
  portions.value = Math.max(1, (Number(portions.value) || 1) - 1)
}

// Preview automatico: porciones -> salsas (uso interno, no se muestra),
// precio unitario, si aplica promo.
const preview = ref(null)
const previewing = ref(false)
let debounceTimer = null

function fetchPreview() {
  clearTimeout(debounceTimer)
  if (!yearId.value || portions.value === null || portions.value < 0) {
    preview.value = null
    return
  }
  debounceTimer = setTimeout(async () => {
    previewing.value = true
    try {
      const { data } = await axios.post('/pricing/preview-portions', {
        year_id: yearId.value,
        portions: portions.value,
      })
      preview.value = data
    } finally {
      previewing.value = false
    }
  }, 250)
}

onMounted(fetchPreview)
watch([yearId, portions], fetchPreview)

const advancedTotal = computed(() => advancedItems.value.reduce((sum, i) => sum + Number(i.line_total), 0))
const total = computed(() => Number(preview.value?.line_total ?? 0) + advancedTotal.value)

function addAdvancedItem(item) {
  advancedItems.value.push(item)
}
function removeAdvancedItem(index) {
  advancedItems.value.splice(index, 1)
}

function money(value) {
  return `$${Number(value ?? 0).toLocaleString('es-AR')}`
}

const duplicateOrders = ref([])
const checkingDuplicates = ref(false)

async function requestSave() {
  if (!client.value) {
    toast.error('Selecciona o crea un cliente antes de guardar.')
    return
  }
  if (!portions.value || portions.value < 1) {
    toast.error('Ingresa al menos 1 porcion de locro.')
    return
  }
  if (isDelivery.value && !deliveryAddress.value?.trim()) {
    toast.error('Ingresa la direccion de entrega para el delivery.')
    return
  }

  // Fase 7, seccion 8: advertencia (no bloqueo) de pedido duplicado.
  checkingDuplicates.value = true
  try {
    const { data } = await axios.get('/orders/check-existing', {
      params: { client_id: client.value.id, year_id: yearId.value },
    })
    duplicateOrders.value = data.orders ?? []
  } catch (e) {
    duplicateOrders.value = []
  } finally {
    checkingDuplicates.value = false
  }

  showConfirm.value = true
}

function confirmSave() {
  saving.value = true
  router.post(
    '/orders',
    {
      client_id: client.value.id,
      year_id: yearId.value,
      rover_id: roverId.value,
      take_away: !isDelivery.value,
      delivery_address: isDelivery.value ? deliveryAddress.value : null,
      observations: observations.value || null,
      portions: portions.value,
      advanced_items: advancedItems.value.map((i) => ({
        type: i.type,
        quantity: i.quantity,
        description: i.description,
        custom_unit_price: i.custom_unit_price,
      })),
    },
    {
      onSuccess: () => toast.success('Pedido creado.'),
      onError: () => {
        toast.error('No se pudo crear el pedido. Revisa los datos.')
        showConfirm.value = false
      },
      onFinish: () => { saving.value = false },
    }
  )
}
</script>

<template>
  <Head title="Nuevo pedido" />

  <AppLayout title="Nuevo pedido">
    <template #header>
      <h2 class="font-semibold text-xl text-gray-800 leading-tight">Nuevo pedido</h2>
    </template>

    <div class="py-8 max-w-2xl mx-auto px-4">
      <div class="bg-gray-900 text-white rounded-lg p-6 space-y-6">
        <div>
          <label class="text-sm text-gray-400 block mb-1">Cliente</label>
          <ClientPicker v-model="client" />
        </div>

        <div>
          <label class="text-sm text-gray-400 block mb-1">Cantidad de porciones de locro</label>
          <div class="flex items-center gap-2">
            <button
              type="button"
              class="w-10 h-10 shrink-0 rounded-md bg-gray-800 border border-gray-600 text-lg leading-none hover:bg-gray-700"
              @click="decPortions"
            >
              −
            </button>
            <input
              v-model.number="portions"
              type="number"
              min="1"
              class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-lg text-center"
            />
            <button
              type="button"
              class="w-10 h-10 shrink-0 rounded-md bg-gray-800 border border-gray-600 text-lg leading-none hover:bg-gray-700"
              @click="incPortions"
            >
              +
            </button>
          </div>
        </div>

        <!-- Resumen de precio (sin salsas: se calculan solas en backend) -->
        <div class="bg-gray-800 rounded-md p-4 text-sm space-y-1">
          <div class="flex justify-between">
            <span class="text-gray-400">Precio unitario</span>
            <strong>
              {{ money(preview?.unit_price) }}
              <span v-if="preview?.is_promo" class="ml-1 px-2 py-0.5 rounded-full bg-green-700 text-xs">Promo</span>
            </strong>
          </div>
          <div class="flex justify-between text-base pt-1 border-t border-gray-700 mt-1">
            <span>Total</span>
            <strong>{{ previewing ? '...' : money(total) }}</strong>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="text-sm text-gray-400 block mb-1">Edicion / Año</label>
            <select v-model="yearId" class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-sm">
              <option v-for="y in years" :key="y.id" :value="y.id">{{ y.year }} {{ y.is_active ? '(activo)' : '' }}</option>
            </select>
          </div>
          <div v-if="canAssignRover">
            <label class="text-sm text-gray-400 block mb-1">Rover responsable</label>
            <select v-model="roverId" class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-sm">
              <option :value="null">Yo mismo</option>
              <option v-for="r in rovers" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
          </div>
        </div>

        <div>
          <div class="flex items-center gap-2">
            <input id="is_delivery" v-model="isDelivery" type="checkbox" />
            <label for="is_delivery" class="text-sm text-gray-300">Es delivery</label>
          </div>
          <p class="text-xs text-gray-500 mt-1">Sin marcar: el cliente retira en mano.</p>

          <div v-if="isDelivery" class="mt-3 space-y-1">
            <label class="text-sm text-gray-400 block mb-1">Dirección de entrega</label>
            <input
              v-model="deliveryAddress"
              type="text"
              class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-sm"
              placeholder="Dirección de entrega para este pedido"
            />
            <p class="text-xs text-yellow-400">
              Confirmá que la dirección de entrega sea correcta antes de guardar el pedido.
            </p>
          </div>
        </div>

        <!-- Observaciones: SIEMPRE visibles, ya no estan dentro de Opciones avanzadas -->
        <div>
          <label class="text-sm text-gray-400 block mb-1">Observaciones del pedido</label>
          <textarea
            v-model="observations"
            rows="2"
            class="w-full bg-gray-800 border border-gray-600 rounded-md px-3 py-2 text-sm"
            placeholder="Opcional"
          ></textarea>
        </div>

        <!-- Opciones avanzadas: solo precio excepcional, y solo si el usuario tiene el permiso -->
        <div v-if="canExceptionalPrice" class="border-t border-gray-700 pt-4">
          <button
            type="button"
            class="text-sm text-gray-400 hover:text-white flex items-center gap-1"
            @click="showAdvanced = !showAdvanced"
          >
            <span>{{ showAdvanced ? '▾' : '▸' }}</span> Opciones avanzadas (precio excepcional)
          </button>

          <div v-if="showAdvanced" class="mt-3 space-y-3">
            <div>
              <h4 class="text-sm text-gray-400 mb-2">Precio excepcional / personalizado</h4>
              <div class="space-y-2 mb-2">
                <div
                  v-for="(item, index) in advancedItems"
                  :key="index"
                  class="flex items-center justify-between bg-gray-800 rounded-md px-3 py-2 text-sm"
                >
                  <span>
                    {{ item.quantity }}x {{ item.type }}
                    <span v-if="item.description" class="text-gray-400">— {{ item.description }}</span>
                  </span>
                  <span class="flex items-center gap-3">
                    {{ money(item.line_total) }}
                    <button type="button" class="text-red-400 hover:text-red-300 text-xs" @click="removeAdvancedItem(index)">
                      Quitar
                    </button>
                  </span>
                </div>
              </div>
              <OrderLineForm v-if="yearId" :year-id="yearId" @submit="addAdvancedItem" />
            </div>
          </div>
        </div>

        <div class="flex justify-end">
          <PrimaryButton :disabled="saving" @click="requestSave">
            Guardar pedido
          </PrimaryButton>
        </div>
      </div>
    </div>

    <!-- Confirmacion antes de guardar -->
    <div v-if="showConfirm" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg p-6 max-w-sm w-full">
        <h3 class="text-lg font-medium text-gray-900 mb-2">Confirmar pedido</h3>

        <!-- Fase 7, seccion 8: advertencia de pedido duplicado. Nunca bloquea,
             solo informa antes de confirmar (el usuario decide si corresponde). -->
        <div v-if="duplicateOrders.length" class="mb-3 bg-yellow-50 border border-yellow-300 rounded-md p-3 text-sm text-yellow-900">
          <p class="font-medium mb-1">
            Este cliente ya tiene {{ duplicateOrders.length === 1 ? 'un pedido' : `${duplicateOrders.length} pedidos` }}
            en esta edicion:
          </p>
          <ul class="space-y-1">
            <li v-for="o in duplicateOrders" :key="o.id">
              Pedido #{{ o.id }} — {{ o.total_portions }} porciones — Saldo pendiente: {{ money(o.balance_due) }}
              <a :href="`/orders/${o.id}/edit`" target="_blank" class="text-blue-700 underline ml-1">Ver</a>
            </li>
          </ul>
          <p class="mt-1 text-xs">Podes crear igualmente otro pedido si corresponde (ej. un segundo encargo).</p>
        </div>

        <p class="text-sm text-gray-600 mb-4">
          {{ client?.first_name }} {{ client?.last_name }} — {{ portions }} porcion(es) de locro —
          Total {{ money(total) }}.
          <span v-if="isDelivery">Delivery a: {{ deliveryAddress }}.</span>
          <span v-else>Retira en mano.</span>
          ¿Confirmas guardar este pedido?
        </p>
        <div class="flex justify-end gap-2">
          <button type="button" class="px-4 py-2 text-sm text-gray-600" @click="showConfirm = false">Cancelar</button>
          <button
            type="button"
            :disabled="saving"
            class="px-4 py-2 text-sm bg-blue-600 text-white rounded-md disabled:opacity-50"
            @click="confirmSave"
          >
            {{ saving ? 'Guardando...' : (duplicateOrders.length ? 'Crear de todas formas' : 'Confirmar') }}
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
