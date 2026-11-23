# Zenli — instrucciones para agentes de desarrollo

Leé primero `docs/decisiones.md`, `docs/modelo-datos.md`, `docs/reglas-agenda.md`, `docs/catalogo.md` y `docs/pendientes.md` antes de modificar código.

- Proyecto nuevo: centro de masoterapia Zenli, sede única en Mendoza, Argentina.
- Stack elegido: Symfony + Doctrine ORM + PostgreSQL; Vue 3 + PrimeVue para el frontend. Monolito modular, API bajo mismo origen y autenticación por sesión para administración.
- Respetá la diferencia entre requisitos confirmados, propuestas y datos pendientes. No inventes precios, duraciones, días individuales ni reglas comerciales.
- Antes de implementar, inspeccioná migraciones existentes y señalá incompatibilidades. No ejecutes migraciones en producción sin autorización.
- Las migraciones en `migrations/` son un borrador v2 heredado, NO validadas contra PostgreSQL. No las trates como esquema definitivo.
- Prioridad: integridad transaccional, permisos por profesional, privacidad de clientes, auditoría, pruebas de concurrencia y seguridad de las reservas.
- No habilites el masaje en pareja para reserva pública hasta confirmar la asignación profesional y su configuración operativa.
- Antes de cambios destructivos, solicitá confirmación. Ejecutá pruebas cuando sea posible e informá qué verificaste realmente.
- Para futuras decisiones, actualizá `docs/decisiones.md` y `docs/pendientes.md`.
- Los formularios de creación y edición deben abrirse en ventanas modales.
