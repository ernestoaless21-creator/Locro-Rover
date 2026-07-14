const ROLE_LABELS = {
  admin: 'Administrador',
  jefe_logistica: 'Jefe de Logística',
  jefe_compras: 'Jefe de Compras',
  jefe_infraestructura: 'Jefe de Infraestructura',
  jefe_publicidad: 'Jefe de Publicidad',
  logistica: 'Logística',
  compras: 'Compras',
  infraestructura: 'Infraestructura',
  publicidad: 'Publicidad',
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
