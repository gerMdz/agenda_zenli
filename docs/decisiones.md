# Decisiones confirmadas

- Marca: **Zenli**. Una sola sede. Dirección mencionada en material aportado: Espejo 167, Ciudad de Mendoza (verificar vigencia antes de publicar).
- Profesionales: **Nataly y Micaela**. Atienden en días diferentes. Ambas realizan los mismos tratamientos, con horarios distintos.
- Un lugar de atención compartido; capacidad inicial modelada como un recurso físico, pendiente confirmar equipamiento real.
- La agenda es el núcleo del producto; sitio público mobile-first, reserva sin crear cuenta, administración separada.
- Cada profesional puede ver y gestionar sus turnos, horarios y bloqueos propios; administración puede gestionar todo.
- Cambios de horario y bloqueos propios son directos solo cuando no afectan reservas existentes; si hay conflicto, rechazar el cambio y mostrarlo.
- Confirmación mixta: automática para algunos casos, manual para otros; pendientes retienen temporalmente recursos.
- Vencimiento de pendientes configurable desde administración. Se sugirió inicialmente 2 horas, pero el borrador de migración v2 usa 24 horas: **inconsistencia por resolver, no considerar 24 horas confirmado**.
- Cancelación y reprogramación por cliente configurables, con ventanas de anticipación; administración puede resolver excepciones, con auditoría.
- Restricciones de clientes: advertencia, revisión manual y bloqueo; registrar motivo y autor, controlar acceso a información sensible. La autoridad exacta para bloquear es propuesta pendiente de confirmación.
- Masaje en pareja: **dos tratamientos consecutivos**, no simultáneos. No se ha confirmado si los realiza una o dos profesionales; no habilitar en reservas públicas.
- Tecnología preferida: Symfony, Doctrine, PostgreSQL, Vue 3, PrimeVue. Sin microservicios ni Kubernetes en primera versión.
- Estética aproximada de marca: verde profundo, dorado y marfil, basada en imágenes aportadas; colores no oficiales.
