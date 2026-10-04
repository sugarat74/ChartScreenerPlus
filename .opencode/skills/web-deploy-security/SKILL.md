---
name: web-deploy-security
description: >-
  Revisa la seguridad de un despliegue web y entrega un informe de validación con
  evidencias, riesgos, correcciones y dictamen. Úsala cuando el usuario pida auditar
  un sitio antes o después de publicarlo, securizar producción, comprobar puertos,
  archivos sensibles, HTTPS, permisos o hardening del hosting. Admite revisión
  estática del repositorio y comprobaciones del servidor o URL disponibles.
  No la uses para un despliegue rutinario sin revisión de seguridad, resolver un
  bug aislado ni certificar cumplimiento normativo.
---

# Revisión de seguridad del despliegue web

Actúa como revisor de seguridad y produce un informe verificable en español
(o en el idioma solicitado). El resultado es una evaluación del alcance observado,
no una promesa de que el sitio es invulnerable.

## Recursos

- Lee `references/checklist.md` para seleccionar los controles aplicables.
- Consulta `references/commands.md` cuando necesites pruebas en Windows, Linux o HTTP.
- Usa `assets/report-template.md` para el informe final, incluso si faltan accesos.
- Las rutas de estos recursos son relativas a esta skill.

## 1. Delimita el despliegue

Identifica repositorio, entorno (desarrollo/staging/producción), URL/hosts propios,
stack/versiones, proveedor, CDN/proxy, document root, servicios y accesos disponibles.
Registra fecha/hora con zona, commit si existe y procedencia de las evidencias.

Si solo tienes código, empieza la revisión estática y pide los datos concretos que
faltan para la validación en vivo. No confundas `.env.example`, un Dockerfile o
tests locales con configuración efectiva de producción. Si el usuario da evidencias
previamente capturadas, indica que no has reproducido las pruebas.

Trabaja en modo **auditoría de solo lectura**, salvo los archivos del informe.
Una petición de revisión permite lecturas y comprobaciones limitadas del objetivo
indicado; no cambies firewall, permisos, cuentas, secretos ni servicios automáticamente.
Si se piden correcciones, acuerda el cambio operativo y su reversión antes de
aplicarlo, especialmente para SSH/RDP, TLS y rotación de claves.

No amplíes una revisión a IPs de terceros, rangos de red ni al origen de una CDN
sin que formen parte del alcance. Usa pocas peticiones, tiempos límite y endpoints
conocidos. No hagas fuerza bruta, DoS, fuzzing masivo ni ejecutes exploits.

## 2. Construye el mapa de superficie

Relaciona navegador → CDN/proxy → web/API → servicios internos → datos/almacenamiento.
Incluye IPv4, IPv6, puertos TCP/UDP, contenedores y publicaciones de puertos, paneles,
workers, scheduler, métricas, copias y acceso administrativo.

Para cada servicio distingue cuatro hechos diferentes:

1. Proceso escuchando y dirección de enlace (`127.0.0.1`, `0.0.0.0`, `::`, etc.).
2. Puerto publicado por contenedor/proxy/NAT.
3. Regla efectiva de firewall del host y proveedor/security group.
4. Alcance externo comprobado desde una ubicación identificada.

`0.0.0.0` no demuestra acceso desde Internet; una prueba local o un timeout remoto
no demuestra aislamiento completo. Un dominio detrás de CDN no representa todo el
origen. Conserva como **NO VERIFICADO** lo que no puedas medir.
No identifiques proceso, protocolo ni entorno solo por un puerto habitual. Usa la
identidad observada; si propones una correspondencia con el stack, márcala como hipótesis.

## 3. Ejecuta los controles

Recorre todas las familias del checklist. Desglosa un control si sus componentes
tienen resultados distintos. Para cada comprobación conserva ID, activo, método,
resultado esperado, resultado observado y evidencia sanitizada (archivo/línea,
comando/exit code o endpoint/estado HTTP). No inventes comandos ejecutados.

Prioriza exposición de secretos/datos, servicios internos, autorización, HTTPS,
configuración de producción y privilegios; después revisa dependencias y operación.

Protege la evidencia: no vuelques `.env`, claves privadas, cookies de sesión,
Authorization, dumps, contenido personal ni configuración completa con secretos.
Registra nombres de variables, presencia y valores no sensibles imprescindibles.
Si aparece un secreto, detén la captura de contenido, redacta el informe y recomienda
contención/rotación; no pruebes si la credencial funciona.

Para archivos protegidos, distingue una fuga real de un `200` con el HTML genérico
de la SPA, un login, una página WAF o un soft-404. Compara con una ruta inexistente
de control y revisa tipo de contenido y un fragmento mínimo redacted, sin descargar
bases de datos, backups ni repositorios. Un `403`/`404` en una ruta no certifica todas
las variantes ni los demás hosts.

Usa pruebas de autenticación/propiedad con cuentas y objetos de prueba en entorno
aislado. En producción limita las lecturas a los datos de prueba autorizados.
No dispares trabajos, altas/bajas ni Ingestion Runs para demostrar un control.
Comprueba la configuración de la base de tests antes de ejecutarlos: algunas suites
borran tablas. No ejecutes `init`, migraciones, seeders o instalaciones en producción.

Las herramientas de auditoría son opcionales: si no existen, registra la limitación
y ofrece el comando pendiente. No instales paquetes ni uses `audit fix` durante la
revisión. Un fallo de red del auditor es **NO VERIFICADO**, no cero vulnerabilidades.

### Adaptación a ChartScreenPlus / AlphaPulse

Cuando sea este repositorio, lee `ARCHITECTURE.md`, `CONSTRAINTS.md`,
`docs/user-and-access-model.md` y las instrucciones locales actuales:

- Laravel: document root `public/`, configuración efectiva `APP_ENV=production`,
  `APP_DEBUG=false`, clave presente sin imprimirla, caché de configuración coherente,
  permisos mínimos en `storage/` y `bootstrap/cache/`.
- Sanctum: sesión first-party, CSRF, orígenes concretos, cookies y proxy confiable.
  La cookie de sesión necesita HttpOnly; `XSRF-TOKEN` debe ser legible por la SPA.
- Visitor puede leer Screener/charts; Registered User solo sus Saved Screeners y
  Watchlist; Admin controla Ingestion Runs. Ocultar botones no es autorización.
- React/Vite: servir build de `frontend/`, no el dev server `5173` ni el prototipo
  `alphapulse/` en `3000`. Las variables `VITE_*` son públicas.
- Engine FastAPI `8090`: interno; confirmar firewall/enlace/proxy y que no haya
  una ruta pública accidental a sus endpoints. Si cruza hosts, revisar autenticación
  de servicio y transporte privado/TLS según topología.
- Puertos `8000`, `8090`, `5173`, `3000` son pistas de desarrollo, no evidencia de
  listeners actuales. SQLite no usa puerto: proteger su fichero y copias, incluidos
  `-wal`/`-shm`. Si hay PostgreSQL/MySQL/Redis, revisar su exposición real.
- Workers/scheduler supervisados, usuario de servicio mínimo, colas y locks
  compartidos/persistentes cuando corresponda, reintentos y límites de recursos.

## 4. Evalúa y prioriza

**Estado por control:**

- **VALIDADO:** evidencia suficiente del resultado esperado, dentro del alcance indicado.
- **FALLA:** desviación comprobada con impacto explicado.
- **NO VERIFICADO:** faltan acceso, herramienta, ejecución o evidencia concluyente.
- **NO APLICA:** motivo concreto ligado a la arquitectura, nunca por falta de acceso.

**Severidad por hallazgo:** CRÍTICA, ALTA, MEDIA, BAJA o INFORMATIVA, justificada
por impacto, exposición y condiciones de explotación. No conviertas cada cabecera
ausente en alta ni inventes CVSS. Separa hechos confirmados de hipótesis.
Una protección cuyo resultado esperado está contradicho **FALLA** aunque su causa
raíz sea desconocida: por ejemplo, una fuga confirmada de `.env` contradice tanto
la protección de archivos como «solo artefactos públicos» del document root. Divide
el control para dejar la configuración/causa no comprobada como NO VERIFICADO.
No presupongas el diseño del stack: la ausencia de HttpOnly en una cookie CSRF
puede ser necesaria, pero validar su funcionamiento exige evidencia adicional.

**Dictamen global (orden de precedencia):**

1. **NO APTO:** un hallazgo confirmado crítico/alto o un fallo en un control esencial
   aplicable bloquea la publicación. Indica exactamente qué lo bloquea.
2. **VALIDACIÓN INCOMPLETA:** no hay bloqueo confirmado, pero algún control esencial
   aplicable sigue sin verificar. Una revisión solo de repositorio no aprueba producción.
3. **APTO CON CONDICIONES:** esenciales validados, sin bloqueos, con riesgos residuales
   no esenciales y plan concreto de tratamiento/verificación.
4. **APTO EN EL ALCANCE REVISADO:** esenciales validados y sin hallazgos pendientes
   aplicables en ese alcance. Expón cualquier exclusión; no lo llames certificación.

Los controles esenciales están señalados en el checklist. Añade los que exija el
modelo de datos o negocio; no retires uno para conseguir un dictamen favorable.
Si conviven fallos y evidencia ausente, el dictamen es NO APTO y se enumeran también
las limitaciones. Cuenta estados por control y severidades por hallazgo por separado.
Recuenta las filas de la matriz (incluidos subcontroles), comprueba que cada ID esté
cubierto y concilia los totales antes de entregar. No inventes fechas de las pruebas:
distingue la fecha real de elaboración de la fecha de captura aportada/desconocida.

## 5. Entrega el informe y el cierre

Guarda una instancia completa de la plantilla en una ubicación de documentación
privada del proyecto, por defecto `docs/security/YYYY-MM-DD-HHmmss-<entorno>.md`.
Comprueba antes que no esté dentro del document root, de un build servido ni publicada
como documentación. Usa otra ruta privada si lo está; no sobrescribas informes previos.
Si no puedes escribir, entrega el informe completo en la respuesta.

Incluye siempre:

- Dictamen, alcance, fecha, evidencias y cobertura por estado.
- Tabla de puertos/servicios y tabla de archivos protegidos, incluso con datos pendientes.
- Matriz de controles, hallazgos priorizados y controles no verificados con próximo paso.
- Remediación verificable por hallazgo: acción específica, responsable propuesto,
  prioridad, prueba de cierre y rollback cuando afecte operación.
- Limitaciones y riesgos residuales; ninguna afirmación de “sitio seguro” sin alcance.

No marques un hallazgo resuelto porque escribiste la solución. Repite la prueba
después del cambio autorizado y adjunta evidencia nueva manteniendo el historial.
Termina en el chat con el dictamen, los bloqueos más importantes, la siguiente acción
y la ruta del informe. Si no hay URL/servidor, identifica los datos necesarios para
completarlo; aun así entrega lo revisado.
