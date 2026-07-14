<script setup>
/**
 * Alta o edicion de un Regalo (porcion regalada/donada) en un modal, sin
 * salir de Gifts/Index.vue. Mismo patron que ClientFormModal.vue: axios
 * directo (no router.post de Inertia) para no navegar, backend responde
 * JSON porque detecta el header Accept (GiftController::store/update ->
 * $request->wantsJson()).
 *
 * Deliberadamente NO pide telefono/apellido/direccion: el pedido explicito
 * del usuario es no exigir un cliente completo para esto.
 */
import { ref, watch } from 'vue'
import axios from 'axios'
import Modal from '@/Components/Modal.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
import InputError from '@/Components/InputError.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import { useToast } from '@/Composables/useToast'

const props = defineProps({
  show: { type: Boolean, default: false },
  gift: { type: Object, default: null }, // null = alta, objeto = edicion
  yearId: { type: Number, required: true },
})

const emit = defineEmits(['close', 'saved'])

const toast = useToast()
const form = ref({ recipient_name: '', quantity: 1, notes: '' })
const errors = ref({})
const saving = ref(false)

watch(() => props.show, (visible) => {
  if (!visible) return
  errors.value = {}
  form.value = props.gift
    ? {
        recipient_name: props.gift.recipient_name ?? '',
        quantity: props.gift.quantity ?? 1,
        notes: props.gift.notes ?? '',
      }
    : { recipient_name: '', quantity: 1, notes: '' }
})

async function submit() {
  saving.value = true
  errors.value = {}

  try {
    const payload = props.gift ? { ...form.value } : { ...form.value, year_id: props.yearId }
    const response = props.gift
      ? await axios.put(`/gifts/${props.gift.id}`, payload)
      : await axios.post('/gifts', payload)

    toast.success(props.gift ? 'Regalo actualizado.' : 'Regalo registrado.')
    emit('saved', response.data.gift)
    emit('close')
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = Object.fromEntries(
        Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]])
      )
    } else {
      toast.error('No se pudo guardar el regalo. Intenta de nuevo.')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Modal :show="show" @close="$emit('close')">
    <div class="p-6">
      <h2 class="text-lg font-medium text-gray-900 mb-4">
        {{ gift ? 'Editar regalo' : 'Nuevo regalo' }}
      </h2>

      <form class="space-y-4" @submit.prevent="submit">
        <div>
          <InputLabel for="recipient_name" value="Nombre o descripción del destinatario" />
          <TextInput
            id="recipient_name"
            v-model="form.recipient_name"
            class="mt-1 block w-full"
            placeholder="Ej: Carsten"
            required
            autofocus
            @keydown.enter.prevent="submit"
          />
          <InputError :message="errors.recipient_name" class="mt-1" />
        </div>

        <div>
          <InputLabel for="quantity" value="Cantidad de porciones" />
          <TextInput
            id="quantity"
            v-model.number="form.quantity"
            type="number"
            min="1"
            class="mt-1 block w-full"
            required
            @keydown.enter.prevent="submit"
          />
          <InputError :message="errors.quantity" class="mt-1" />
        </div>

        <div>
          <InputLabel for="notes" value="Observación (opcional)" />
          <textarea
            id="notes"
            v-model="form.notes"
            rows="2"
            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            placeholder="Ej: Ayudó en cocina"
          ></textarea>
          <InputError :message="errors.notes" class="mt-1" />
        </div>
      </form>

      <div class="mt-6 flex justify-end gap-2">
        <SecondaryButton @click="$emit('close')">Cancelar</SecondaryButton>
        <PrimaryButton :disabled="saving || !form.recipient_name || !form.quantity" @click="submit">
          {{ saving ? 'Guardando...' : 'Guardar' }}
        </PrimaryButton>
      </div>
    </div>
  </Modal>
</template>
