const ROLE_LABELS = {
  admin: 'Administrador',
  jefe_logistica: 'Jefe de Logística',
  logistica: 'Logística',
  vendedor: 'Vendedor',
  rover: 'Rover',
  jefe_equipo: 'Jefe de Equipo',
  colaborador: 'Colaborador',
  caja: 'Caja',
  produccion: 'Producción',
}

export function roleLabel(roleName) {
  if (!roleName) return '—'
  return ROLE_LABELS[roleName] ?? roleName
}
