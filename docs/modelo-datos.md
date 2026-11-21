# Modelo de datos — borrador v2

Las migraciones de la agenda v2 crean estas 18 tablas:

- Identidad: `usuarios`, `profesionales`.
- Clientes: `clientes`, `clientes_restricciones`.
- Catálogo: `tratamientos`, `servicios`, `servicio_sesiones`.
- Agenda: `recursos`, `horarios_profesionales`, `bloqueos`.
- Reservas: `reservas`, `reserva_participantes`, `reserva_sesiones`, `ocupaciones_profesionales`, `ocupaciones_recursos`.
- Comercial: `promociones`, `reserva_promociones`.
- Control: `reservas_eventos`, `politicas_reservas`.

La migración de administración agrega `configuracion_portada` para el teléfono, la imagen y el último autor del cambio, e `intentos_acceso_admin` para el límite de intentos de inicio de sesión. La migración `Version20261119000200` agrega los enlaces opcionales de Instagram y Facebook a `configuracion_portada`.

La migración `Version20261119000300` agrega `servicios_portada` para gestionar los masajes que se muestran en la portada pública. Cada servicio tiene nombre, descripción corta (pie de tarjeta), detalle extenso (modal), imagen individual (archivo binario con versión SHA-256), orden de visualización, flag de visibilidad y auditoría (autor y fecha). La migración no inserta servicios iniciales.

La migración `Version20261119000400` agrega dirección y horarios informativos a `configuracion_portada`, conservando inicialmente los textos publicados; no configura disponibilidad de agenda.

La migración `Version20261120000300` agrega cinco indicadores globales de visibilidad, inicialmente verdaderos, a `configuracion_portada`. No modifica los registros individuales ni la configuración de profesionales.

Una `reserva` es la cabecera comercial; `reserva_participantes` identifica participantes y `reserva_sesiones` representa cada tratamiento, profesional e intervalo. `servicios` define ofertas reservables y `servicio_sesiones` su composición. Una reserva puede tener varias sesiones consecutivas. El recurso físico se ocupa por sesión.

Se usan `BIGINT` para IDs, `TIMESTAMPTZ` para instantes, `DATE` y `TIME` para vigencias y franjas semanales. Zona horaria prevista: `America/Argentina/Mendoza`.

Las ocupaciones incluyen exclusiones GiST por profesional o recurso para impedir solapamientos. Las ocupaciones activas se liberan al cancelar, mientras se conserva la reserva y su historial.

## Aspectos que requieren revisión antes de ejecutar

- Las migraciones siguen siendo un borrador. La prueba de agenda ejecutó su SQL mediante Doctrine DBAL en PostgreSQL 14 e hizo una prueba de concurrencia y exclusión GiST. Queda pendiente validar PostgreSQL 17 y el ciclo completo de migraciones.
- El mapeo ORM es parcial: no representa todas las tablas ni todas las columnas. No usar `doctrine:schema:update` ni generar migraciones automáticamente desde este mapeo; podrían proponerse cambios destructivos sobre datos y restricciones existentes.
- No hay en el borrador una definición de tipos de recursos y compatibilidades complejas; se usa un solo recurso físico.
- Verificar consistencia entre `reserva_sesiones`, `reserva_participantes`, `reservas` y `ocupaciones_*`.
- Verificar reglas de sesiones consecutivas, duración total y asignación de profesionales.
- Definir comportamiento de ocupaciones y auditoría al cancelar, vencer, reprogramar o cambiar profesional.
- Garantizar que modificaciones de bloqueos y horarios compitan transaccionalmente con nuevas reservas.
- Definir cómo identificar/verificar clientes y controlar intentos de eludir restricciones, sin usar coincidencias de nombre como identidad.
- Revisar política de expiración: migración v2 propone 24 h; discusión anterior recomendaba 2 h.
- Revisar índices, constraints, integridad referencial, permisos, migraciones reversibles y Doctrine schema diff.
