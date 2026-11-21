# Integración de promociones

La migración `Version20261120000100` agrega una tabla independiente; debe validarse junto con las migraciones existentes en PostgreSQL antes de desplegarla. No ejecutarla en producción sin autorización.

La administración está integrada en la sección Promociones del menú Sitio.

Las promociones se crean ocultas; el texto es informativo y no aplica descuentos ni reglas a las reservas. La eliminación es lógica y conserva el último autor, sin constituir un historial completo de auditoría. La vigencia y las condiciones comerciales siguen pendientes.