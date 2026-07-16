<script setup>
/**
 * Configuracion de precios/promocion/datos de cada edicion (Year). Solo
 * accesible con permiso 'parametros.gestionar' (gateado en backend, no solo
 * ocultando el link). Permite:
 * - Ver todas las ediciones y activar una (la anterior se desactiva sola).
 * - Crear una edicion nueva.
 * - Editar los parametros de la edicion seleccionada, con preview en vivo
 *   de como quedarian los precios (sin guardar nada hasta tocar "Guardar").
 *
 * IMPORTANTE: guardar estos parametros NUNCA recalcula pedidos ya existentes
 * (ver YearController::update). Solo afecta pedidos nuevos o a pedidos donde
 * se edite explicitamente su cantidad de porciones despues.
 */
import { Head, router } from '@inertiajs/vue3'
import { ref, computed, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  years: { type: Array, required: true },
})

const toast = useToast()

const selectedYearId = ref(props.years.find((y) => y.is_active)?.id ?? props.years[0]?.id ?? null)
const selectedYear = computed(() => props.years.find((y) => y.id === selectedYearId.value) ?? null)

const form = ref({})
const saving = ref(false)

function loadForm(year) {
  form.value = year
    ? {
        label: year.label ?? '',
        portion_price: year.portion_price ?? 0,
        promo_unit_price: year.promo_unit_price,
        amount_for_promo: year.amount_for_promo,
        made_portions: year.made_portions ?? 0,
        sale_date: year.sale_date ?? '',
        notes: year.notes ?? '',
        sauce_portions_per_block: year.sauce_portions_per_block ?? 2,
        sauce_units_per_block: year.sauce_units_per_block ?? 1,
        sales_goal_global: year.sales_goal_global,
        sales_goal_individual_default: year.sales_goal_individual_default,
      }
    : {}
}
watch(selectedYear, loadForm, { immediate: true })

const promoEnabled = ref(!!(selectedYear.value?.promo_unit_price && selectedYear.value?.amount_for_promo))
watch(selectedYear, (y) => { promoEnabled.value = !!(y?.promo_unit_price && y?.amount_for_promo) })

// Preview de precios para 1..6 porciones con los valores ACTUALES del formulario
// (sin guardar), para que se entienda de inmediato el efecto de cambiar algo.
const previewRows = computed(() => {
  const rows = []
  for (let portions = 1; portions <= 6; portions++) {
    const promoActive = promoEnabled.value
      && form.value.amount_for_promo
      && form.value.promo_unit_price
      && portions >= form.value.amount_for_promo
    const unitPrice = promoActive ? form.value.promo_unit_price : form.value.portion_price
    rows.push({
      portions,
      total: Number(unitPrice ?? 0) * portions,
      isPromo: promoActive,
    })
  }
  return rows
})

/**
 * Espejo en JS de PricingService::calculateSauces (ver docblock ahi para la
 * formula completa y la ambiguedad documentada sobre bloques parciales).
 * Solo para previsualizar en vivo sin guardar; el backend siempre vuelve a
 * calcular esto por su cuenta, este resultado nunca se envia al servidor.
 */
function calculateSaucesPreview(portions) {
  if (portions <= 0) return 0
  const portionsPerBlock = Math.max(1, Number(form.value.sauce_portions_per_block) || 2)
  const unitsPerBlock = Math.max(0, Number(form.value.sauce_units_per_block) || 0)
  if (unitsPerBlock === 0) return 0
  return Math.max(unitsPerBlock, Math.floor(portions / portionsPerBlock) * unitsPerBlock)
}

const saucePreviewRows = computed(() => {
  const rows = []
  for (let portions = 0; portions <= 6; portions++) {
    rows.push({ portions, sauces: calculateSaucesPreview(portions) })
  }
  return rows
})

function money(value) {
  return `$${Number(value ?? 0).toLocaleString('es-AR')}`
}

const showSaveConfirm = ref(false)

function requestSave() {
  if (!selectedYear.value) return
  showSaveConfirm.value = true
}

/**
 * El recalculo es SIEMPRE una decision explicita del usuario en este mismo
 * dialogo, cada vez que guarda: nunca hay un default que recalcule solo.
 * 'recalculate' llega true/false segun el boton que se haya tocado.
 */
function confirmSave(recalculate) {
  saving.value = true
  showSaveConfirm.value = false

  const payload = {
    ...form.value,
    promo_unit_price: promoEnabled.value ? form.value.promo_unit_price : null,
    amount_for_promo: promoEnabled.value ? form.value.amount_for_promo : null,
    // Nunca se manda null aca: las columnas de years son NOT NULL (con
    // default 2/1 a nivel de migracion). Si el usuario borro el campo, se
    // cae a ese mismo default para no romper el guardado.
    sauce_portions_per_block: Number(form.value.sauce_portions_per_block) || 2,
    sauce_units_per_block: form.value.sauce_units_per_block !== '' && form.value.sauce_units_per_block !== null
      ? Number(form.value.sauce_units_per_block)
      : 1,
    sales_goal_global: form.value.sales_goal_global !== '' && form.value.sales_goal_global != null
      ? Number(form.value.sales_goal_global)
      : null,
    sales_goal_individual_default: form.value.sales_goal_individual_default !== '' && form.value.sales_goal_individual_default != null
      ? Number(form.value.sales_goal_individual_default)
      : null,
    recalculate_orders: recalculate,
  }

  router.put(`/years/${selectedYear.value.id}`, payload, {
    preserveScroll: true,
    onSuccess: (page) => {
      const msg = page.props.flash?.success ?? 'Parametros guardados.'
      toast.success(msg)
      router.reload({ only: ['years'] })
    },
    onError: () => toast.error('No se pudo guardar. Revisa los datos.'),
    onFinish: () => { saving.value = false },
  })
}

function activateYear(year) {
  if (!confirm(`¿Activar la edicion ${year.year}? Dejara de estar activa la edicion actual.`)) return
  router.post(`/years/${year.id}/activate`, {}, {
    onSuccess: () => toast.success(`Edicion ${year.year} activada.`),
  })
}

// Alta de nueva edicion
const showNewYear = ref(false)
const newYear = ref({ year: new Date().getFullYear() + 1, label: '', portion_price: 0, event_type: 'locro' })

function createYear() {
  router.post('/years', newYear.value, {
    onSuccess: () => {
      toast.success('Edicion creada.')
      showNewYear.value = false
      router.reload({ only: ['years'] })
    },
    onError: () => toast.error('No se pudo crear la edicion. Revisa los datos (el anio debe ser unico).'),
  })
}
</script>

<template>
  <Head title="Parámetros" />

  <AppLayout title="Parámetros">
    <template #header>
      <h2 class="font-semibold text-xl text-white leading-tight">Parámetros de precios y ediciones</h2>
    </template>

    <div class="py-4 max-w-3xl mx-auto px-4 space-y-6">
      <!-- Listado de ediciones -->
      <div class="bg-surface border border-border text-white rounded-lg p-5">
        <div class="flex justify-between items-center mb-3">
          <h3 class="text-sm text-gray-400">Ediciones</h3>
          <button type="button" class="text-blue-400 hover:text-blue-300 text-sm" @click="showNewYear = !showNewYear">
            + Nueva edición
          </button>
        </div>

        <div v-if="showNewYear" class="bg-surface-3 rounded-md p-4 mb-4 space-y-2">
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-xs text-gray-400">Año</label>
              <input v-model.number="newYear.year" type="number" class="w-full bg-surface border border-border-soft rounded-md px-2 py-1 text-sm" />
            </div>
            <div>
              <label class="text-xs text-gray-400">Precio por porción</label>
              <input v-model.number="newYear.portion_price" type="number" min="0" step="0.01" class="w-full bg-surface border border-border-soft rounded-md px-2 py-1 text-sm" />
            </div>
          </div>
          <input v-model="newYear.label" type="text" placeholder="Etiqueta (ej: Locro 2027)" class="w-full bg-surface border border-border-soft rounded-md px-2 py-1 text-sm" />
          <div class="flex justify-end">
            <PrimaryButton @click="createYear">Crear edición</PrimaryButton>
          </div>
        </div>

        <div class="space-y-1">
          <div
            v-for="y in years"
            :key="y.id"
            class="flex items-center justify-between px-3 py-2 rounded-md text-sm cursor-pointer"
            :class="selectedYearId === y.id ? 'bg-surface-3' : 'hover:bg-surface-3/60'"
            @click="selectedYearId = y.id"
          >
            <span>
              {{ y.year }} — {{ y.label }}
              <span v-if="y.is_active" class="ml-2 px-2 py-0.5 rounded-full bg-green-700 text-xs">Activa</span>
            </span>
            <button
              v-if="!y.is_active"
              type="button"
              class="text-green-400 hover:text-green-300 text-xs"
              @click.stop="activateYear(y)"
            >
              Activar
            </button>
          </div>
        </div>
      </div>

      <!-- Edicion de parametros -->
      <div v-if="selectedYear" class="bg-surface border border-border text-white rounded-lg p-5 space-y-4">
        <h3 class="text-sm text-gray-400">Editando: {{ selectedYear.year }}</h3>

        <div>
          <label class="text-sm text-gray-400 block mb-1">Etiqueta</label>
          <input v-model="form.label" type="text" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm" />
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="text-sm text-gray-400 block mb-1">Precio normal por porción</label>
            <input v-model.number="form.portion_price" type="number" min="0" step="0.01" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="text-sm text-gray-400 block mb-1">Porciones elaboradas</label>
            <input v-model.number="form.made_portions" type="number" min="0" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm" />
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="text-sm text-gray-400 block mb-1">Fecha de venta</label>
            <input v-model="form.sale_date" type="date" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm" />
          </div>
        </div>

        <div class="border-t border-border pt-4">
          <label class="flex items-center gap-2 text-sm mb-3">
            <input v-model="promoEnabled" type="checkbox" />
            Activar promoción por cantidad
          </label>

          <div v-if="promoEnabled" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="text-sm text-gray-400 block mb-1">Porciones mínimas para promo</label>
              <input v-model.number="form.amount_for_promo" type="number" min="1" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="text-sm text-gray-400 block mb-1">Precio promocional por porción</label>
              <input v-model.number="form.promo_unit_price" type="number" min="0" step="0.01" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm" />
            </div>
          </div>
          <p v-if="promoEnabled" class="text-xs text-gray-500 mt-2">
            La promoción se aplica a partir de esa cantidad (incluida), a TODAS las porciones del pedido.
          </p>
        </div>

        <div class="border-t border-border pt-4">
          <h4 class="text-sm text-gray-400 mb-2">Configuración de salsas</h4>
          <p class="text-xs text-gray-500 mb-3">
            Definí la relación "X salsas cada Y porciones" para esta edición. La regla anterior
            (1 salsa cada 2 porciones) es la que ya viene cargada por defecto.
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="text-sm text-gray-400 block mb-1">Salsas por bloque</label>
              <input v-model.number="form.sauce_units_per_block" type="number" min="0" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm" />
            </div>
            <div>
              <label class="text-sm text-gray-400 block mb-1">Porciones por bloque</label>
              <input v-model.number="form.sauce_portions_per_block" type="number" min="1" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm" />
            </div>
          </div>
          <p class="text-xs text-gray-500 mt-2">
            Ej: 1 salsa cada 2 porciones, o 2 salsas cada 3 porciones. Cualquier pedido con al
            menos 1 porción recibe como mínimo las salsas de un bloque completo, aunque no llegue
            a completarlo.
          </p>

          <div class="bg-surface-3 rounded-md p-4 mt-3">
            <h5 class="text-xs text-gray-400 mb-2">Vista previa de salsas (con los valores de arriba, sin guardar)</h5>
            <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 text-sm">
              <div v-for="row in saucePreviewRows" :key="row.portions" class="flex justify-between bg-surface rounded px-2 py-1">
                <span class="text-gray-400">{{ row.portions }} porc.</span>
                <span class="font-medium">{{ row.sauces }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="border-t border-border pt-4">
          <h4 class="text-sm text-gray-400 mb-2">Metas de venta (Fase 6A)</h4>
          <p class="text-xs text-gray-500 mb-3">
            Opcionales. Si se dejan vacías, el Dashboard no muestra barra de progreso para esa meta.
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="text-sm text-gray-400 block mb-1">Meta global de porciones</label>
              <input v-model.number="form.sales_goal_global" type="number" min="1" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm" placeholder="Ej: 1500" />
            </div>
            <div>
              <label class="text-sm text-gray-400 block mb-1">Meta de ventas por Rover</label>
              <input v-model.number="form.sales_goal_individual_default" type="number" min="1" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm" placeholder="Ej: 70" />
            </div>
          </div>
        </div>

        <!-- Preview en vivo -->
        <div class="bg-surface-3 rounded-md p-4">
          <h4 class="text-xs text-gray-400 mb-2">Vista previa (con los valores de arriba, sin guardar)</h4>
          <div class="space-y-1 text-sm">
            <div v-for="row in previewRows" :key="row.portions" class="flex justify-between">
              <span>{{ row.portions }} porción(es)</span>
              <span>
                {{ money(row.total) }}
                <span v-if="row.isPromo" class="ml-1 px-2 py-0.5 rounded-full bg-green-700 text-xs">Promo</span>
              </span>
            </div>
          </div>
        </div>

        <div>
          <label class="text-sm text-gray-400 block mb-1">Notas</label>
          <textarea v-model="form.notes" rows="2" class="w-full bg-surface-3 border border-border-soft rounded-md px-3 py-2 text-sm"></textarea>
        </div>

        <p class="text-xs text-gray-500">
          Guardar estos cambios no recalcula pedidos existentes automaticamente: al guardar vas a poder elegir si querés recalcularlos o dejarlos como estan.
        </p>

        <div class="flex justify-end">
          <PrimaryButton :disabled="saving" @click="requestSave">
            {{ saving ? 'Guardando...' : 'Guardar parámetros' }}
          </PrimaryButton>
        </div>
      </div>
    </div>

    <!-- Confirmacion: recalcular o no los pedidos existentes de esta edicion -->
    <div v-if="showSaveConfirm" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg p-6 max-w-md w-full">
        <h3 class="text-lg font-medium text-gray-900 mb-2">Guardar parámetros de {{ selectedYear?.year }}</h3>
        <p class="text-sm text-gray-600 mb-2">
          Elegí que hacer con los pedidos que ya existen en esta edición:
        </p>
        <ul class="text-sm text-gray-600 list-disc pl-5 mb-4 space-y-1">
          <li><strong>Recalcular</strong> actualiza el importe y el saldo de cada pedido existente (no cancelado) usando estos nuevos valores.</li>
          <li>Los <strong>pagos ya registrados no se modifican</strong> en ningún caso; el saldo se vuelve a calcular a partir de ellos.</li>
          <li>Los pedidos <strong>cancelados</strong> nunca se recalculan.</li>
        </ul>
        <div class="flex flex-col gap-2">
          <button
            type="button"
            class="px-4 py-2 text-sm bg-green-600 text-white rounded-md hover:bg-green-500"
            @click="confirmSave(true)"
          >
            Guardar y recalcular pedidos
          </button>
          <button
            type="button"
            class="px-4 py-2 text-sm bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300"
            @click="confirmSave(false)"
          >
            Guardar sin recalcular
          </button>
          <button
            type="button"
            class="px-4 py-2 text-sm text-gray-500 hover:text-gray-700"
            @click="showSaveConfirm = false"
          >
            Cancelar
          </button>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
