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

## Implementación de la disponibilidad

- El cálculo inicial consulta un día de Mendoza para una sesión individual en el espacio compartido. Recibe las franjas de profesionales activos y habilitados, y los bloqueos y ocupaciones activas del espacio y de cada profesional.
- La duración, el paso entre inicios y los minutos de preparación y limpieza se pasan explícitamente. Las franjas contiguas o superpuestas de una misma persona se unen y el primer inicio posible se calcula desde la apertura más la preparación.
- La preparación y la limpieza ocupan tanto a la profesional como al espacio. Se usan intervalos con inicio incluido y fin excluido, igual que en las exclusiones del borrador PostgreSQL.
- El servicio interno `AgendaPostgresql` lee las franjas, los bloqueos y las ocupaciones de `ESPACIO_1`. Solo consulta servicios públicos, activos y de una sesión individual. Se conservan las ocupaciones existentes como fuente de disponibilidad hasta que se liberen.
- Para registrar una reserva de confirmación automática, el servicio bloquea la fila del espacio con `FOR UPDATE` en una transacción `READ COMMITTED`, vuelve a calcular el turno y guarda la reserva, sus ocupaciones y el evento de confirmación en la misma transacción. Las escrituras futuras de horarios y bloqueos deberán adquirir primero ese mismo bloqueo.
