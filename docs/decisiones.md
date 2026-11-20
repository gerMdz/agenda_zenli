# Decisiones confirmadas

- Marca: **Zenli**. Una sola sede. Dirección confirmada: Espejo 167, Ciudad de Mendoza.
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
- La primera agenda operativa es privada y está en `/admin/agenda`. Usa los servicios ya cargados en administración y permite definir la preparación general o por servicio. El inicio y la duración se ingresan al cargar cada turno.
- Un turno interno ocupa un espacio y a cada profesional asignada, durante el servicio y su preparación. Con un único espacio activo, la selección es automática. Los turnos internos comparten las exclusiones de ocupación de las reservas.
- La guía de agenda propone confirmar los espacios, vincular las cuentas de profesionales, cargar servicios y su preparación, y luego registrar turnos. Una profesional puede crear y gestionar sus turnos individuales y consultar los turnos compartidos en que participa. Administración puede gestionar cualquier turno interno.
- Editar un turno vuelve a comprobar todas las ocupaciones dentro de la transacción. Cancelarlo libera la ocupación del espacio y de las profesionales. El historial conserva el estado anterior y quién hizo cada cambio. Los mensajes de agenda son privados entre la cuenta remitente y la destinataria.
- La agenda abre en vista semanal, con alternativas diaria y mensual. El alta y la edición se hacen en un modal y las horas se ingresan y muestran en formato de 24 horas de Mendoza.
- Una misma cuenta puede tener permisos administrativos y estar vinculada a una profesional. Los turnos propios se identifican por ese vínculo y se pueden destacar y filtrar dentro del calendario.

## Portada y administración

- La portada pública presenta los servicios de masajes, el centro y las consultas por WhatsApp. El teléfono inicial es el que ya estaba publicado en el sitio.
- Las cuentas administradoras ingresan por sesión en `/admin`. Pueden configurar el teléfono y la imagen de la portada, gestionar los servicios de la portada y administrar otras cuentas. La configuración y el último autor del cambio se guardan en PostgreSQL.
- Los perfiles de Instagram y Facebook son opcionales y se editan en `/admin`. La portada muestra los enlaces configurados junto a los datos de contacto.
- El modo «Mostrar solo la imagen de fondo» reemplaza la portada pública por la imagen principal a pantalla completa. Se activa con «Bajar sitio» y se desactiva con «Subir sitio» desde la sección Portada de `/admin`. Queda visible la misma imagen principal configurada.
- Los servicios de la portada se gestionan desde `/admin` con CRUD completo: crear, editar, eliminar, reordenar y mostrar/ocultar. Cada servicio tiene nombre, descripción corta, detalle extenso e imagen individual. La portada los carga desde `/api/servicios` y muestra un modal con detalle al hacer clic en "Ver detalle".
- La dirección y los horarios generales publicados se editan desde administración como texto informativo; no determinan disponibilidad ni horarios de profesionales. Se confirmaron como vigentes los valores iniciales: lunes, miércoles y viernes de 10:00 a 20:00; martes, jueves y sábados de 11:00 a 19:00.
