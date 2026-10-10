# Verificación por entrega

## Estudio y planificación

- Evidencias apuntan a archivos observados; propuestas se distinguen del estado actual.
- El árbol no contradice despliegues/toolchains existentes sin una tarea de transición.
- Cada entrega tiene resultado, dependencia, responsable de integración y verificación.
- Se identifican condiciones para cambiar la recomendación (gráfico, módulo nativo,
  acceso a iOS, versiones incompatibles); no se promete reutilización de pantallas DOM.
- Fuentes técnicas primarias con fecha; estimaciones propias y supuestos separados.

## API y credenciales

- Mismo recurso produce datos/ranking equivalentes en web y móvil; fechas y nulls
  conservan significado. Contrato anterior sigue funcionando.
- Login correcto/incorrecto, registro, throttle, 401/403/404/422/429 y traducción es/en.
- Peticiones Bearer reales sin cookies/CSRF; sesiones SPA reales con CSRF. Las pruebas
  no se limitan a Sanctum::actingAs, que puede ocultar errores de emisión/transporte.
- Expiración, logout de un dispositivo, revocación remota y cambio de cuenta.
- A no lee ni modifica Watchlists/Screeners de B; abilities no evitan probar ownership.
- Token móvil, incluso de un Admin, respeta el límite operativo decidido.
- Auditoría registra origen web/móvil sin token/password; revocar sesión web no se
  presenta como revocar dispositivo. El nuevo endpoint también respeta retención.
- Migraciones y pruebas Laravel en SQLite y PostgreSQL/PgBouncer según CI del proyecto.

## Paquetes y aplicaciones

- Generación reproducible de contratos/tipos sin diff inesperado.
- Paquetes puros sin imports DOM, React Native, credenciales o configuración de cliente.
- Instalación limpia desde el lockfile acordado, web build/test y bundling móvil.
- Metro resuelve React y módulos nativos correctamente.
- Idioma persistente por dispositivo, fechas de mercado sin desplazamientos por zona,
  USD sin conversión implícita; formato es/en y texto largo no rompen pantallas.
- Navegación/ticker, filtros, ranking, chart, login, Watchlist y Saved Screeners en
  Android e iOS; red lenta, offline, foreground/background y respuesta fuera de orden.
- Caché privada se elimina al cerrar sesión/cambiar usuario. No se muestra como
  sincronizada una mutación fallida ni como actual información almacenada antigua.
- Gráfico con payload límite: gestos, rotación, crosshair, atribución y puente validado.
- Lectores de pantalla, escalado de texto, safe areas, teclado y controles táctiles.

## Builds e integración

- Gate estándar conserva Laravel, web y engine; añadir checks móviles reproducibles
  cuando existan. No convertir falta de SDK iOS en un éxito ficticio.
- Registrar por separado test JS, build Android, build iOS, prueba en dispositivo,
  distribución interna y publicación. Lo no ejecutado queda pendiente.
- Shared/API changes activan checks de consumidores y compatibilidad de la versión
  móvil anterior soportada.
- Paquetes de release web excluyen móvil/signing/artefactos nativos; CI móvil no
  activa el despliegue VPS por error. Conserva fuentes compartidas necesarias al build.
- Credenciales de signing fuera del Git; builds de preview apuntan al entorno previsto.
- Antes de tiendas: revisar políticas actuales, permisos, privacidad y borrado de cuenta
  con el flujo real de registro; validación no equivale a aprobación de la tienda.

## Ensayos de uso de la skill

Para revisar esta skill sin construir una app, ejecutar o inspeccionar estos escenarios:
1. “Evalúa llevar Chartiko a móvil”: produce estudio; no scaffolding ni deploy.
2. “Implementa login móvil”: primero verifica sesión existente, modelo de tokens,
   contratos, expiración, auditoría y pruebas de cookie/Bearer; no reemplaza auth web.
3. “Trabajamos web y móvil a la vez”: propone contratos, ramas cortas y gates de
   consumidores, sin separar permanentemente los targets Android/iOS.
4. “Solo tengo Windows”: distingue Android local, iOS remoto, simulador y QA real.
5. “Reutiliza el gráfico”: exige spike con opciones y evidencia; no afirma compatibilidad
   directa de componentes DOM ni elimina atribuciones.
Estos escenarios son criterios de revisión, no evidencia de que se hayan ejecutado.

