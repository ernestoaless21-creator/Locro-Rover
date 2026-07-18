import { computed, unref } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Fase 19: unico punto de verdad del lado del frontend para "esta edicion se
 * puede modificar ahora mismo". Espeja exactamente la regla del backend
 * (Year::isEditableBy, gateada via Gate::authorize('mutate', $year) en cada
 * controlador) -- edicion activa, o usuario con 'anios.gestionar'. Esto es
 * SOLO una afordancia de UI (ocultar/deshabilitar botones); la autorizacion
 * real siempre se re-chequea en el servidor.
 *
 * @param {import('vue').Ref<object>|(() => object)} yearRef objeto Year (o
 *   getter que lo devuelve) con al menos { is_active }.
 */
export function useEditableYear(yearRef) {
  const page = usePage()
  const can = (perm) => (page.props.permissions ?? []).includes(perm)

  return computed(() => {
    const year = typeof yearRef === 'function' ? yearRef() : unref(yearRef)

    return Boolean(year?.is_active) || can('anios.gestionar')
  })
}
