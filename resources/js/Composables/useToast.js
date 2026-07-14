import { reactive } from 'vue'

/**
 * Estado module-level (no per-componente): todos los componentes que importan
 * este archivo comparten la MISMA lista de toasts, asi un componente hijo
 * (ej. AssignOrderModal) puede disparar un toast que se muestra en el
 * ToastContainer montado en la pagina padre, sin pasar props/emits por 3 niveles.
 */
const toasts = reactive([])
let nextId = 1

function push(message, type = 'success', timeout = 4000) {
  const id = nextId++
  toasts.push({ id, message, type })
  if (timeout > 0) {
    setTimeout(() => dismiss(id), timeout)
  }
  return id
}

function dismiss(id) {
  const index = toasts.findIndex((t) => t.id === id)
  if (index !== -1) toasts.splice(index, 1)
}

export function useToast() {
  return {
    toasts,
    success: (msg) => push(msg, 'success'),
    error: (msg) => push(msg, 'error', 6000),
    info: (msg) => push(msg, 'info'),
    dismiss,
  }
}
