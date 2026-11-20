export const gruposMenu = [
  {
    nombre: 'Sitio',
    enlaces: [
      { id: 'portada', nombre: 'Portada' },
      { id: 'servicios', nombre: 'Servicios' },
    ],
  },
  {
    nombre: 'Agenda',
    enlaces: [
      { id: 'espacios', nombre: 'Espacios de atención' },
      { id: 'profesionales', nombre: 'Profesionales' },
      { id: 'horarios-profesionales', nombre: 'Horarios' },
      { id: 'preparacion', nombre: 'Preparación' },
      { id: 'configuracion-agenda', nombre: 'Guía de configuración' },
    ],
  },
  { nombre: 'Acceso', enlaces: [{ id: 'cuentas', nombre: 'Cuentas administradoras' }] },
]
export const secciones = gruposMenu.flatMap(grupo => grupo.enlaces)

