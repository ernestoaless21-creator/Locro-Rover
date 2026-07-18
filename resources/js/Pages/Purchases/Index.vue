<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import YearSelector from '@/Components/YearSelector.vue'
import Modal from '@/Components/Modal.vue'
import HistoricalEditionBanner from '@/Components/HistoricalEditionBanner.vue'
import { useToast } from '@/Composables/useToast'
import { useEditableYear } from '@/Composables/useEditableYear'

const props = defineProps({
  team:       { type: String,  required: true },
  year:       { type: Object,  required: true },
  items:      { type: Array,   required: true },
  products:   { type: Array,   required: true },
  categories: { type: Array,   required: true },
  suppliers:  { type: Array,   required: true },
  totals:     { type: Object,  required: true },
  canManage:  { type: Boolean, required: true },
})

const toast = useToast()
const canMutateYear = useEditableYear(() => props.year)
// Fase 19: gatea la edicion/alta/baja de items de planificacion (year-scoped).
// El boton "✎" de editar PRODUCTO DE CATALOGO (linea del template) queda
// fuera de este gate: PurchaseProduct no pertenece a ninguna edicion.
const canManageNow = computed(() => props.canManage && canMutateYear.value)

const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 })
function fmtMoney(v) {
  if (v === null || v === undefined || v === '') return '—'
  return money.format(Number(v))
}
function fmtQty(v) {
  if (v === null || v === undefined || v === '') return '—'
  return Number(v).toLocaleString('es-AR', { maximumFractionDigits: 3 })
}
function n(v) {
  return v === null || v === undefined || v === '' ? null : Number(v)
}

// ─── Filtro / búsqueda ────────────────────────────────────────────────────────
const search = ref('')
const activeCategoryId = ref(null) // null = todas

const filteredItems = computed(() => {
  let list = props.items
  if (activeCategoryId.value !== null) {
    list = list.filter((i) => i.product.purchase_category_id === activeCategoryId.value)
  }
  if (search.value.trim()) {
    const q = search.value.trim().toLowerCase()
    list = list.filter((i) => i.product.name.toLowerCase().includes(q))
  }
  return list
})

const groupedItems = computed(() => {
  const groups = new Map()
  for (const item of filteredItems.value) {
    const key = item.product.category?.name ?? 'Sin categoría'
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(item)
  }
  return [...groups.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([category, items]) => ({ category, items }))
})

// ─── Edición inline por fila (estilo planilla) ─────────────────────────────────
const rowState = reactive({})

function fieldsOf(item) {
  return {
    qty_1000:              n(item.qty_1000),
    qty_1500:               n(item.qty_1500),
    unit:                   item.unit ?? '',
    actual_quantity:        n(item.actual_quantity),
    estimated_total_price:  n(item.estimated_total_price),
    actual_total_price:     n(item.actual_total_price),
    planned_supplier_id:    item.planned_supplier_id ?? '',
    actual_supplier_id:     item.actual_supplier_id ?? '',
    notes:                  item.notes ?? '',
  }
}

for (const item of props.items) {
  rowState[item.id] = fieldsOf(item)
}
watch(() => props.items, (items) => {
  for (const item of items) {
    if (!rowState[item.id]) rowState[item.id] = fieldsOf(item)
  }
})

function saveItem(item) {
  const payload = { ...rowState[item.id] }
  payload.planned_supplier_id = payload.planned_supplier_id || null
  payload.actual_supplier_id = payload.actual_supplier_id || null
  router.put(route('purchases.items.update', { team: props.team, item: item.id }), payload, {
    preserveScroll: true,
    preserveState: true,
    onError: () => toast.error('Error al guardar el ítem.'),
  })
}

function deleteItem(item) {
  if (!window.confirm(
    `¿Quitar "${item.product.name}" de la planificación de esta edición?\n\n` +
    'Esto no elimina el producto: seguirá disponible en el catálogo para agregarlo en esta u otras ediciones.'
  )) return
  router.delete(route('purchases.items.destroy', { team: props.team, item: item.id }), {
    preserveScroll: true,
    onSuccess: () => toast.success('Ítem quitado de esta planificación.'),
  })
}

// ─── Editar producto del catálogo (nombre / categoría / unidad / notas) ────────
const editingProduct = ref(null) // objeto producto siendo editado, o null
const editProductForm = useForm({ name: '', purchase_category_id: '', unit: '', notes: '' })

function openEditProduct(product) {
  editingProduct.value = product
  editProductForm.reset()
  editProductForm.clearErrors()
  editProductForm.name = product.name
  editProductForm.purchase_category_id = product.purchase_category_id ?? ''
  editProductForm.unit = product.unit ?? ''
  editProductForm.notes = product.notes ?? ''
}

function closeEditProduct() {
  editingProduct.value = null
}

function submitEditProduct() {
  if (!editProductForm.name.trim()) return
  editProductForm.put(route('purchases.products.update', { team: props.team, product: editingProduct.value.id }), {
    preserveScroll: true,
    onSuccess: () => { closeEditProduct(); toast.success('Producto actualizado.') },
    onError: () => toast.error('Error al actualizar el producto.'),
  })
}

// ─── Nuevo ítem ────────────────────────────────────────────────────────────────
const showNewItem = ref(false)
const useNewProduct = ref(false)

const newItemForm = useForm({
  purchase_product_id: '',
  new_product_name: '',
  new_product_category_id: '',
  qty_1000: '',
  qty_1500: '',
  unit: '',
  estimated_total_price: '',
  planned_supplier_id: '',
  year_id: props.year.id,
})

const existingProductIds = computed(() => new Set(props.items.map((i) => i.purchase_product_id)))
const availableProducts = computed(() =>
  props.products.filter((p) => p.is_active && !existingProductIds.value.has(p.id))
)

function openNewItem() {
  newItemForm.reset()
  newItemForm.year_id = props.year.id
  useNewProduct.value = false
  showNewItem.value = true
}

function onProductPicked() {
  const product = availableProducts.value.find((p) => p.id === Number(newItemForm.purchase_product_id))
  newItemForm.unit = product?.unit ?? newItemForm.unit
}

const selectedExistingProduct = computed(() =>
  newItemForm.purchase_product_id
    ? props.products.find((p) => p.id === Number(newItemForm.purchase_product_id))
    : null
)

function submitNewItem() {
  if (useNewProduct.value) {
    newItemForm.purchase_product_id = ''
    if (!newItemForm.new_product_name.trim()) return
  } else {
    newItemForm.new_product_name = ''
    newItemForm.new_product_category_id = ''
    if (!newItemForm.purchase_product_id) return
  }

  newItemForm.post(route('purchases.items.store', props.team), {
    preserveScroll: true,
    onSuccess: () => { showNewItem.value = false; newItemForm.reset() },
    onError: () => toast.error('Error al agregar el producto.'),
  })
}

// ─── Nueva categoría (quick-add) ────────────────────────────────────────────────
const showNewCategory = ref(false)
const newCategoryForm = useForm({ name: '' })

function submitNewCategory() {
  if (!newCategoryForm.name.trim()) return
  newCategoryForm.post(route('purchases.categories.store', props.team), {
    preserveScroll: true,
    onSuccess: () => { showNewCategory.value = false; newCategoryForm.reset() },
    onError: () => toast.error('Error al crear la categoría.'),
  })
}
</script>

<template>
  <Head :title="`Planificación de compras — Edición ${year.year}`" />
  <AppLayout :title="`Planificación de compras — Edición ${year.year}`">
    <template #header>
      <div class="flex items-center gap-4">
        <a :href="route('teams.show', team)" class="text-xs text-ember hover:text-ember-strong uppercase tracking-wide">
          ← Compras
        </a>
        <h2 class="font-semibold text-xl text-white leading-tight">Planificación de compras</h2>
      </div>
    </template>

    <div class="py-8 max-w-7xl mx-auto px-4">

      <!-- Año + navegación -->
      <div class="mb-6 space-y-3">
        <div class="flex items-center justify-between flex-wrap gap-3">
          <YearSelector :selected-year-id="year.id" />
          <div class="flex items-center gap-4 text-sm">
            <a :href="route('suppliers.index', { team })" class="text-indigo-600 hover:text-indigo-800">Proveedores</a>
            <a v-if="canManage" :href="route('purchases.import', { team, target_year_id: year.id })" class="text-indigo-600 hover:text-indigo-800">
              Importar desde otra edición
            </a>
          </div>
        </div>
        <HistoricalEditionBanner :year="year" />
      </div>

      <!-- Totales -->
      <div class="mb-6 bg-white rounded-lg shadow-sm border border-gray-100 p-4 flex flex-wrap gap-x-8 gap-y-2 text-sm">
        <div>
          <span class="text-gray-400">Total estimado:</span>
          <span class="ml-1 font-semibold text-gray-800">{{ fmtMoney(totals.estimated) }}</span>
        </div>
        <div>
          <span class="text-gray-400">Total real registrado:</span>
          <span class="ml-1 font-semibold text-gray-800">{{ fmtMoney(totals.real) }}</span>
          <span v-if="totals.items_without_real_price > 0" class="ml-2 text-amber-600 text-xs">
            · {{ totals.items_without_real_price }} compra{{ totals.items_without_real_price === 1 ? '' : 's' }} realizada{{ totals.items_without_real_price === 1 ? '' : 's' }} sin precio real
          </span>
        </div>
      </div>

      <!-- Filtros -->
      <div class="mb-4 flex flex-wrap items-center gap-2">
        <button
          type="button"
          class="px-3 py-1 rounded-full text-xs font-medium"
          :class="activeCategoryId === null ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
          @click="activeCategoryId = null"
        >
          Todas
        </button>
        <button
          v-for="c in categories"
          :key="c.id"
          type="button"
          class="px-3 py-1 rounded-full text-xs font-medium"
          :class="activeCategoryId === c.id ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
          @click="activeCategoryId = c.id"
        >
          {{ c.name }}
        </button>
        <input
          v-model="search"
          type="text"
          placeholder="Buscar producto..."
          class="ml-auto w-48 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        />
      </div>

      <!-- Tabla -->
      <div class="bg-white rounded-lg shadow-sm border border-gray-100 overflow-x-auto">
        <table class="min-w-[1150px] w-full text-sm">
          <thead>
            <tr class="border-b border-gray-200 text-xs text-gray-400 uppercase tracking-wide">
              <th class="text-left px-3 py-2 font-medium">Producto</th>
              <th class="text-center px-2 py-2 font-medium w-24">1000 porciones</th>
              <th class="text-center px-2 py-2 font-medium w-24">1500 porciones</th>
              <th class="text-left px-2 py-2 font-medium w-20">Unidad</th>
              <th class="text-center px-2 py-2 font-medium w-24">Cantidad real</th>
              <th class="text-center px-2 py-2 font-medium w-28">Precio estimado</th>
              <th class="text-center px-2 py-2 font-medium w-28">Precio real</th>
              <th class="text-left px-3 py-2 font-medium w-36">Proveedor previsto</th>
              <th class="text-left px-3 py-2 font-medium w-36">Proveedor real</th>
              <th class="text-left px-3 py-2 font-medium w-40">Observaciones</th>
              <th v-if="canManageNow" class="w-8"></th>
            </tr>
          </thead>
          <template v-for="group in groupedItems" :key="group.category">
            <tbody>
              <tr class="bg-gray-50">
                <td :colspan="canManageNow ? 11 : 10" class="px-3 py-1.5 text-xs font-semibold text-gray-500 uppercase tracking-wide">
                  {{ group.category }}
                </td>
              </tr>
              <tr v-for="item in group.items" :key="item.id" class="border-b border-gray-50 last:border-b-0 align-top">
                <td class="px-3 py-2 font-medium text-gray-800 whitespace-nowrap">
                  {{ item.product.name }}
                  <button
                    v-if="canManage"
                    type="button"
                    class="ml-1 text-gray-300 hover:text-indigo-600 text-xs align-middle"
                    title="Editar producto del catálogo (nombre, categoría, unidad)"
                    @click="openEditProduct(item.product)"
                  >✎</button>
                </td>

                <template v-if="canManageNow">
                  <td class="px-1 py-1.5">
                    <input v-model="rowState[item.id].qty_1000" type="number" step="any" min="0"
                      class="w-full text-center text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @blur="saveItem(item)" @keydown.enter="$event.target.blur()" />
                  </td>
                  <td class="px-1 py-1.5">
                    <input v-model="rowState[item.id].qty_1500" type="number" step="any" min="0"
                      class="w-full text-center text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @blur="saveItem(item)" @keydown.enter="$event.target.blur()" />
                  </td>
                  <td class="px-1 py-1.5">
                    <input v-model="rowState[item.id].unit" type="text" placeholder="kg"
                      class="w-full text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @blur="saveItem(item)" @keydown.enter="$event.target.blur()" />
                  </td>
                  <td class="px-1 py-1.5">
                    <input v-model="rowState[item.id].actual_quantity" type="number" step="any" min="0"
                      class="w-full text-center text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @blur="saveItem(item)" @keydown.enter="$event.target.blur()" />
                  </td>
                  <td class="px-1 py-1.5">
                    <div class="flex items-center gap-0.5">
                      <span class="text-gray-300 text-[10px]">$</span>
                      <input v-model="rowState[item.id].estimated_total_price" type="number" step="any" min="0"
                        class="w-full text-center text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                        @blur="saveItem(item)" @keydown.enter="$event.target.blur()" />
                    </div>
                  </td>
                  <td class="px-1 py-1.5">
                    <div class="flex items-center gap-0.5">
                      <span class="text-gray-300 text-[10px]">$</span>
                      <input v-model="rowState[item.id].actual_total_price" type="number" step="any" min="0"
                        class="w-full text-center text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                        @blur="saveItem(item)" @keydown.enter="$event.target.blur()" />
                    </div>
                  </td>
                  <td class="px-1 py-1.5">
                    <select v-model="rowState[item.id].planned_supplier_id"
                      class="w-full text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @change="saveItem(item)">
                      <option value="">—</option>
                      <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                  </td>
                  <td class="px-1 py-1.5">
                    <select v-model="rowState[item.id].actual_supplier_id"
                      class="w-full text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @change="saveItem(item)">
                      <option value="">—</option>
                      <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                  </td>
                  <td class="px-1 py-1.5">
                    <input v-model="rowState[item.id].notes" type="text" placeholder="Observaciones"
                      class="w-full text-xs rounded border-gray-200 focus:border-indigo-400 focus:ring-indigo-400"
                      @blur="saveItem(item)" @keydown.enter="$event.target.blur()" />
                  </td>
                  <td class="px-1 py-1.5 text-center">
                    <button type="button" class="text-gray-300 hover:text-red-500 text-xs"
                      title="Quitar de esta planificación (el producto sigue disponible en el catálogo)"
                      @click="deleteItem(item)">✕</button>
                  </td>
                </template>

                <template v-else>
                  <td class="px-2 py-2 text-center text-gray-600">{{ fmtQty(item.qty_1000) }}</td>
                  <td class="px-2 py-2 text-center text-gray-600">{{ fmtQty(item.qty_1500) }}</td>
                  <td class="px-2 py-2 text-gray-500">{{ item.unit ?? '—' }}</td>
                  <td class="px-2 py-2 text-center text-gray-600">{{ fmtQty(item.actual_quantity) }}</td>
                  <td class="px-2 py-2 text-center text-gray-600">{{ fmtMoney(item.estimated_total_price) }}</td>
                  <td class="px-2 py-2 text-center text-gray-600">{{ fmtMoney(item.actual_total_price) }}</td>
                  <td class="px-3 py-2 text-gray-500">{{ item.plannedSupplier?.name ?? '—' }}</td>
                  <td class="px-3 py-2 text-gray-500">{{ item.actualSupplier?.name ?? '—' }}</td>
                  <td class="px-3 py-2 text-gray-500">{{ item.notes ?? '—' }}</td>
                </template>
              </tr>
            </tbody>
          </template>
        </table>

        <div v-if="filteredItems.length === 0" class="text-center text-gray-400 py-10 text-sm">
          <template v-if="items.length === 0">
            <p class="text-2xl mb-1">🛒</p>
            <p>Todavía no hay productos planificados para esta edición.</p>
          </template>
          <template v-else>
            <p class="text-2xl mb-1">🔎</p>
            <p>No se encontraron productos.</p>
          </template>
        </div>
      </div>

      <!-- Agregar producto -->
      <div v-if="canManageNow" class="mt-4">
        <button v-if="!showNewItem" type="button" class="text-sm text-indigo-600 hover:text-indigo-800" @click="openNewItem">
          + Agregar producto
        </button>

        <!--
          NO es un <form>: si lo fuera, presionar Enter en el campo "Nueva
          categoría" (anidado más abajo) dispararía el submit de TODO este
          bloque y crearía el producto antes de tiempo. La creación ocurre
          únicamente al presionar "Agregar" de forma explícita.
        -->
        <div v-else class="bg-white rounded-lg shadow-sm border border-gray-100 p-4 space-y-3">
          <div class="flex items-center gap-4 text-xs">
            <label class="flex items-center gap-1.5 cursor-pointer">
              <input type="radio" :checked="!useNewProduct" @change="useNewProduct = false" class="text-indigo-600" />
              Producto existente
            </label>
            <label class="flex items-center gap-1.5 cursor-pointer">
              <input type="radio" :checked="useNewProduct" @change="useNewProduct = true" class="text-indigo-600" />
              Nuevo producto
            </label>
          </div>

          <div v-if="!useNewProduct">
            <label class="block text-xs font-medium text-gray-700 mb-1">Producto</label>
            <div class="flex items-center gap-2">
              <select v-model="newItemForm.purchase_product_id" @change="onProductPicked"
                class="flex-1 rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                <option value="">Seleccionar producto…</option>
                <option v-for="p in availableProducts" :key="p.id" :value="p.id">
                  {{ p.category ? `${p.category.name} — ` : '' }}{{ p.name }}
                </option>
              </select>
              <button
                v-if="selectedExistingProduct"
                type="button"
                class="text-xs text-indigo-600 hover:text-indigo-800 whitespace-nowrap"
                @click="openEditProduct(selectedExistingProduct)"
              >
                ✎ Editar producto
              </button>
            </div>
            <p v-if="newItemForm.errors.purchase_product_id" class="text-xs text-red-600 mt-1">{{ newItemForm.errors.purchase_product_id }}</p>
          </div>

          <div v-else class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Nombre del producto *</label>
              <input v-model="newItemForm.new_product_name" type="text" placeholder="Ej: Maíz blanco"
                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                @keydown.enter.prevent />
              <p v-if="newItemForm.errors.new_product_name" class="text-xs text-red-600 mt-1">{{ newItemForm.errors.new_product_name }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Categoría</label>
              <div class="flex gap-2">
                <select v-model="newItemForm.new_product_category_id"
                  class="flex-1 rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                  <option value="">Sin categoría</option>
                  <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <button type="button" class="text-xs text-indigo-600 hover:text-indigo-800 whitespace-nowrap"
                  @click="showNewCategory = !showNewCategory">
                  + categoría
                </button>
              </div>
            </div>
          </div>

          <div v-if="showNewCategory" class="flex gap-2 items-end bg-gray-50 rounded-lg p-2">
            <div class="flex-1">
              <label class="block text-xs font-medium text-gray-700 mb-1">Nueva categoría</label>
              <input v-model="newCategoryForm.name" type="text" placeholder="Ej: Verdulería"
                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                @keydown.enter.prevent="submitNewCategory" />
              <p v-if="newCategoryForm.errors.name" class="text-xs text-red-600 mt-1">{{ newCategoryForm.errors.name }}</p>
            </div>
            <button type="button" class="px-3 py-1.5 bg-green-600 text-white text-xs rounded-lg hover:bg-green-500"
              @click="submitNewCategory">
              Crear
            </button>
          </div>

          <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">1000 porciones</label>
              <input v-model="newItemForm.qty_1000" type="number" step="any" min="0"
                class="w-full rounded-lg border-gray-300 text-sm text-center focus:ring-indigo-500 focus:border-indigo-500"
                @keydown.enter.prevent />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">1500 porciones</label>
              <input v-model="newItemForm.qty_1500" type="number" step="any" min="0"
                class="w-full rounded-lg border-gray-300 text-sm text-center focus:ring-indigo-500 focus:border-indigo-500"
                @keydown.enter.prevent />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Unidad</label>
              <input v-model="newItemForm.unit" type="text" placeholder="kg"
                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                @keydown.enter.prevent />
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Precio estimado ($)</label>
              <div class="flex items-center gap-1">
                <span class="text-gray-400 text-sm">$</span>
                <input v-model="newItemForm.estimated_total_price" type="number" step="any" min="0"
                  class="w-full rounded-lg border-gray-300 text-sm text-center focus:ring-indigo-500 focus:border-indigo-500"
                  @keydown.enter.prevent />
              </div>
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Proveedor previsto (opcional)</label>
            <select v-model="newItemForm.planned_supplier_id"
              class="w-full sm:w-64 rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
              <option value="">Sin definir</option>
              <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>

          <div class="flex gap-2 pt-1">
            <button type="button" :disabled="newItemForm.processing" @click="submitNewItem"
              class="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-500 disabled:opacity-50">
              Agregar
            </button>
            <button type="button" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900" @click="showNewItem = false">
              Cancelar
            </button>
          </div>
        </div>
      </div>

      <!-- Editar producto del catálogo -->
      <Modal :show="editingProduct !== null" @close="closeEditProduct">
        <div class="p-6" v-if="editingProduct">
          <h2 class="text-lg font-medium text-gray-900 mb-1">Editar producto</h2>
          <p class="text-xs text-gray-400 mb-4">
            Esto corrige el producto en el catálogo compartido entre ediciones, no solo en esta planificación.
          </p>

          <div class="space-y-3">
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Nombre *</label>
              <input v-model="editProductForm.name" type="text"
                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                @keydown.enter.prevent="submitEditProduct" />
              <p v-if="editProductForm.errors.name" class="text-xs text-red-600 mt-1">{{ editProductForm.errors.name }}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Categoría</label>
                <select v-model="editProductForm.purchase_category_id"
                  class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                  <option value="">Sin categoría</option>
                  <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Unidad predeterminada</label>
                <input v-model="editProductForm.unit" type="text" placeholder="kg"
                  class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
                  @keydown.enter.prevent="submitEditProduct" />
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Observaciones generales (opcional)</label>
              <textarea v-model="editProductForm.notes" rows="2"
                class="w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
          </div>

          <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900" @click="closeEditProduct">
              Cancelar
            </button>
            <button type="button" :disabled="editProductForm.processing || !editProductForm.name.trim()"
              class="px-4 py-2 bg-green-600 text-white text-sm rounded-lg hover:bg-green-500 disabled:opacity-50"
              @click="submitEditProduct">
              Guardar
            </button>
          </div>
        </div>
      </Modal>

    </div>
  </AppLayout>
</template>
