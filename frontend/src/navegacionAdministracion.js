export const gruposMenu = [
  {
    nombre: 'Agenda',
    enlaces: [
      { id: 'servicios', nombre: 'Servicios' },
      { id: 'profesionales', nombre: 'Profesionales' },
      { id: 'espacios', nombre: 'Espacios de atención' },
      { id: 'horarios-profesionales', nombre: 'Horarios' },
      { id: 'preparacion', nombre: 'Preparación' },
      { id: 'configuracion-agenda', nombre: 'Guía de configuración' },
    ],
  },
  {
    nombre: 'Sitio público',
    enlaces: [
      { id: 'portada', nombre: 'Portada' },
      { id: 'servicios-portada', nombre: 'Servicios en portada' },
      { id: 'promociones', nombre: 'Promociones' },
      { id: 'equipo-portada', nombre: 'Profesionales en portada' },
    ],
  },
  {
    nombre: 'Acceso',
    enlaces: [
      { id: 'cuentas', nombre: 'Cuentas administradoras' },
      { id: 'auditoria', nombre: 'Auditoría' },
    ],
  },
]
export const secciones = gruposMenu.flatMap(grupo => grupo.enlaces)

