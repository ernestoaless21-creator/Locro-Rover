<script setup>
/**
 * Alta o edicion de un cliente en un modal, sin salir de la pantalla donde se
 * invoca (Clients/Index.vue o el picker de Orders/New.vue). Usa axios directo
 * (no router.post de Inertia) para no navegar/recargar la pagina host; el
 * backend responde JSON porque detecta el header Accept que manda axios
 * (ver ClientController::store/update -> $request->wantsJson()).
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
  client: { type: Object, default: null }, // null = alta, objeto = edicion
})

const emit = defineEmits(['close', 'saved'])

const toast = useToast()
const form = ref({ first_name: '', last_name: '', phone: '', address: '', postal_code: '', general_notes: '' })
const errors = ref({})
const saving = ref(false)

watch(() => props.show, (visible) => {
  if (!visible) return
  errors.value = {}
  form.value = props.client
    ? {
        first_name: props.client.first_name ?? '',
        last_name: props.client.last_name ?? '',
        phone: props.client.phone ?? '',
        address: props.client.address ?? '',
        postal_code: props.client.postal_code ?? '',
        general_notes: props.client.general_notes ?? '',
      }
    : { first_name: '', last_name: '', phone: '', address: '', postal_code: '', general_notes: '' }
})

async function submit() {
  saving.value = true
  errors.value = {}

  try {
    const response = props.client
      ? await axios.put(`/clients/${props.client.id}`, form.value)
      : await axios.post('/clients', form.value)

    toast.success(props.client ? 'Cliente actualizado.' : 'Cliente creado.')
    emit('saved', response.data.client)
    emit('close')
  } catch (e) {
    if (e.response?.status === 422) {
      errors.value = Object.fromEntries(
        Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]])
      )
    } else {
      toast.error('No se pudo guardar el cliente. Intenta de nuevo.')
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
        {{ client ? 'Editar cliente' : 'Nuevo cliente' }}
      </h2>

      <form class="space-y-4" @submit.prevent="submit">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <InputLabel for="first_name" value="Nombre" />
            <TextInput
              id="first_name"
              v-model="form.first_name"
              class="mt-1 block w-full"
              required
              autofocus
              @keydown.enter.prevent="submit"
            />
            <InputError :message="errors.first_name" class="mt-1" />
          </div>
          <div>
            <InputLabel for="last_name" value="Apellido" />
            <TextInput
              id="last_name"
              v-model="form.last_name"
              class="mt-1 block w-full"
              @keydown.enter.prevent="submit"
            />
            <InputError :message="errors.last_name" class="mt-1" />
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <InputLabel for="phone" value="Telefono" />
            <TextInput
              id="phone"
              v-model="form.phone"
              class="mt-1 block w-full"
              placeholder="11-1234-5678"
              @keydown.enter.prevent="submit"
            />
            <InputError :message="errors.phone" class="mt-1" />
          </div>
          <div>
            <InputLabel for="postal_code" value="Codigo postal" />
            <TextInput
              id="postal_code"
              v-model="form.postal_code"
              class="mt-1 block w-full"
              @keydown.enter.prevent="submit"
            />
          </div>
        </div>

        <div>
          <InputLabel for="address" value="Direccion" />
          <TextInput
            id="address"
            v-model="form.address"
            class="mt-1 block w-full"
            @keydown.enter.prevent="submit"
          />
          <InputError :message="errors.address" class="mt-1" />
        </div>

        <div>
          <InputLabel for="general_notes" value="Observaciones generales" />
          <textarea
            id="general_notes"
            v-model="form.general_notes"
            rows="2"
            class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
          ></textarea>
        </div>
      </form>

      <div class="mt-6 flex justify-end gap-2">
        <SecondaryButton @click="$emit('close')">Cancelar</SecondaryButton>
        <PrimaryButton :disabled="saving || !form.first_name" @click="submit">
          {{ saving ? 'Guardando...' : 'Guardar' }}
        </PrimaryButton>
      </div>
    </div>
  </Modal>
</template>
