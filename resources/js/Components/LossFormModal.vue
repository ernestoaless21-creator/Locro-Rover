<script setup>
/**
 * Alta o edicion de una Perdida (porciones perdidas) en un modal. Mismo
 * patron que GiftFormModal.vue / ClientFormModal.vue.
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
  loss: { type: Object, default: null }, // null = alta, objeto = edicion
  yearId: { type: Number, required: true },
})

const emit = defineEmits(['close', 'saved'])

const toast = useToast()
const form = ref({ quantity: 1, reason: '' })
const errors = ref({})
const saving = ref(false)

watch(() => props.show, (visible) => {
  if (!visible) return
  errors.value = {}
  form.value = props.loss
    ? { quantity: props.loss.quantity ?? 1, reason: props.loss.reason ?? '' }
    : { quantity: 1, reason: '' }
})

async function submit() {
  saving.value = true
  errors.value = {}

  try {
    const payload = props.loss ? { ...form.value } : { ...form.value, year_id: props.yearId }
    const response = props.loss
      ? await axios.put(`/losses/${props.loss.id}`, payload)
      : await axios.post('/losses', payload)

    toast.success(props.loss ? 'Pérdida actualizada.' : 'Pérdida registrada.')
    emit('saved', response.data.loss)
    emit('close')
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = Object.fromEntries(
        Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]])
      )
    } else {
      toast.error('No se pudo guardar la pérdida. Intenta de nuevo.')
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
        {{ loss ? 'Editar pérdida' : 'Nueva pérdida' }}
      </h2>

      <form class="space-y-4" @submit.prevent="submit">
        <div>
          <InputLabel for="quantity" value="Cantidad de porciones" />
          <TextInput
            id="quantity"
            v-model.number="form.quantity"
            type="number"
            min="1"
            class="mt-1 block w-full"
            required
            autofocus
            @keydown.enter.prevent="submit"
          />
          <InputError :message="errors.quantity" class="mt-1" />
        </div>

        <div>
          <InputLabel for="reason" value="Motivo (opcional)" />
          <textarea
            id="reason"
            v-model="form.reason"
            rows="2"
            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            placeholder="Ej: Se cayó una olla"
          ></textarea>
          <InputError :message="errors.reason" class="mt-1" />
        </div>
      </form>

      <div class="mt-6 flex justify-end gap-2">
        <SecondaryButton @click="$emit('close')">Cancelar</SecondaryButton>
        <PrimaryButton :disabled="saving || !form.quantity" @click="submit">
          {{ saving ? 'Guardando...' : 'Guardar' }}
        </PrimaryButton>
      </div>
    </div>
  </Modal>
</template>
