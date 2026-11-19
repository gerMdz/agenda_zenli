# Reglas de agenda y reservas

## Disponibilidad

Calcular horarios libres a partir de servicio/sesiones, profesionales, franjas recurrentes, vigencias, bloqueos, reservas existentes y recurso compartido. No precargar turnos vacíos. La reserva requiere una asignación válida de profesional y recurso durante todos los intervalos de sus sesiones, incluyendo limpieza/preparación si se configura.

La consulta de disponibilidad es orientativa: al confirmar, recalcular y asignar dentro de una transacción. Usar bloqueos transaccionales consistentes y exclusiones GiST como defensa final ante concurrencia.

Con las dependencias de Composer y los binarios locales de PostgreSQL instalados, `bash tests/agenda-postgresql.sh` crea un clúster temporal, aplica las migraciones con datos ficticios y verifica que solo una de dos confirmaciones concurrentes pueda ocupar el espacio. Usa un socket Unix privado y elimina el clúster al terminar. `php tests/agenda-availability.php` prueba el cálculo en memoria.

## Estados (borrador v2)

`PENDIENTE`, `CONFIRMADA`, `RECHAZADA`, `CANCELADA`, `VENCIDA`, `COMPLETADA`, `AUSENTE`. La conversación también usó `EXPIRADA`; unificar nomenclatura antes de codificar enums y API.

Una pendiente retiene ocupación provisional hasta confirmación, rechazo, cancelación o vencimiento. Expiración mediante comando Symfony + cron, y comprobación sincrónica en toda operación sensible. Si vence, liberar ocupación y registrar evento. Reprogramación es operación auditada, no necesariamente un estado.

## Políticas

Configurables: duración máxima pendiente, anticipación mínima y máxima para reservar, plazo para cancelar, plazo para reprogramar, excepciones administrativas, reglas de aprobación manual por tratamiento y otras condiciones. Los valores de 12 h para cancelar/reprogramar, 2 h de anticipación y 30 días de horizonte son recomendaciones, NO confirmaciones.

## Permisos

Administración: todas las agendas, recursos, catálogo, políticas, restricciones e informes. Profesional: sus reservas, horarios y bloqueos, sin acceso a datos personales de otras profesionales. Los permisos deben validarse en backend, por rol y alcance (p. ej. Symfony Voters). El cambio de horario que afecte reservas se rechaza sin modificaciones parciales.

## Restricciones

`ADVERTENCIA`: aviso interno; `REVISION`: obliga a aprobación manual; `BLOQUEO`: impide reserva pública. Comprobar cada participante. Registrar auditoría y restringir acceso a descripciones sensibles. No mostrar motivos privados al cliente.

## Notificaciones

Eventos de negocio para solicitud, confirmación, rechazo, cancelación, reprogramación y vencimiento. Correo inicial; WhatsApp automatizado no comprometido. Considerar outbox transaccional.
