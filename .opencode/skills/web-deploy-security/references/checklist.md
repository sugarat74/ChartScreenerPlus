# Controles del despliegue web

Cada ID debe figurar en la matriz con un estado y evidencia o motivo. Un ID puede
dividirse en subcontroles. **E** = esencial si aplica al sistema. Las condiciones de
aplicación forman parte de la evidencia. No es una certificación OWASP/ASVS.

## NET — Puertos, red y acceso al host

| ID | E | Comprobación y resultado esperado |
|---|---|---|
| NET-01 | Sí | Inventariar listeners TCP/UDP, IPv4/IPv6, interfaces, procesos y puertos de contenedor/NAT. Ningún servicio accesible sin propósito documentado. |
| NET-02 | Sí | Firewall host y proveedor con entrada mínima; DB, caché, motor, métricas y dev servers aislados. Confirmar alcance desde fuera del host cuando sea posible y registrar origen de la prueba. |
| NET-03 | Sí | SSH/RDP/paneles limitados por VPN/bastion/allowlist u otro control equivalente; acceso administrativo robusto, sin contraseñas por defecto. Revisar llaves/MFA y privilegios sin cerrar la vía de recuperación. |
| NET-04 | No | Restricciones de salida/SSRF según integraciones, timeouts y destinos permitidos; DNS A/AAAA/CNAME y registros huérfanos. No reclamar un recurso externo para demostrar takeover. |

Política orientativa, no regla universal: TCP 443 público; TCP 80 solo redirección
o ACME si se usa; UDP 443 solo si HTTP/3 está habilitado intencionalmente. SSH 22/RDP
3389 restringidos, no necesariamente cerrados. Servicios internos como DB 3306/5432,
Redis 6379, FPM 9000, Docker API 2375/2376 no deben exponerse sin arquitectura y
controles explícitos. Tenerlos instalados no significa que estén escuchando.

## FILE — Ficheros, document root y secretos

| ID | E | Comprobación y resultado esperado |
|---|---|---|
| FILE-01 | Sí | Document root y reglas de routing/alias correctos: solo artefactos públicos; sin PHP servido como fuente, directory listing ni enlaces a directorios privados. |
| FILE-02 | Sí | `.env` y variantes, `.git/HEAD`/`.git/config`, backups, dumps, bases de datos, logs, claves y configuraciones privadas no accesibles por HTTP. Probar lista acotada adaptada al stack; registrar ruta por ruta. |
| FILE-03 | Sí | Secretos fuera de repositorio/build, frontend, logs, imágenes/capas y artefactos CI públicos. Credenciales únicas por entorno, alcance mínimo, proceso de rotación; no imprimir valores. |
| FILE-04 | Sí | Propietarios y permisos/ACL mínimos en código, secretos, uploads y datos. Usuario web no administrador/root; nada de `777` o `Everyone:FullControl` como solución. Escritura limitada a directorios necesarios. |
| FILE-05 | No | Uploads separados y sin ejecución: tamaño, tipo real/MIME, nombre generado, autorización de descarga y rutas sin traversal. Source maps revisados según contenido, no vulnerabilidad automática. |

Ejemplos adaptables: `/.env`, `/.env.production`, `/.env.bak`, `/.git/HEAD`,
`/storage/logs/laravel.log`, `/database/database.sqlite`, `/backup.zip`,
`/composer.json`, `/package.json`. Metadatos de paquetes no equivalen a una fuga de
secretos; valorar contenido. No buscar nombres de backups por fuerza bruta.
No descargar archivos masivos ni extraer `.git`; metadatos y muestreo limitado bastan.
`robots.txt`/`noindex` y `.gitignore` no son controles de acceso.
Permitir `/.well-known/acme-challenge/` puede ser necesario: no bloquear todos los
dotfiles indiscriminadamente sin contemplarlo.

## TLS — HTTPS y frontera proxy

| ID | E | Comprobación y resultado esperado |
|---|---|---|
| TLS-01 | Sí | HTTPS con nombre/cadena/caducidad válidos, renovación operativa y TLS moderno (1.2/1.3); versiones antiguas deshabilitadas donde se pueda verificar. No usar `-k` como evidencia de éxito. |
| TLS-02 | Sí | HTTP redirige a HTTPS donde aplique, sin enviar credenciales primero; origen/CDN/proxy mantiene confidencialidad conforme a la topología. No confundir certificado del edge con el del origen. |
| TLS-03 | Sí | Trusted proxies y hosts restringidos; no confiar arbitrariamente en X-Forwarded-* de Internet. Esquema HTTPS, IP real para rate limits y URLs de recuperación correctos. |
| TLS-04 | No | HSTS en HTTPS tras comprobar HTTPS completo; `includeSubDomains`/preload solo tras verificar todos los subdominios y valorar reversibilidad. Mixed content ausente. |

## HTTP — Cabeceras, CORS y cachés

| ID | E | Comprobación y resultado esperado |
|---|---|---|
| HTTP-01 | No | CSP acorde con recursos reales, protección de framing vía `frame-ancestors` o X-Frame-Options, `nosniff`, Referrer-Policy y Permissions-Policy relevantes. CSP Report-Only no equivale a aplicación. |
| HTTP-02 | Sí | CORS solo permite los orígenes necesarios; no refleja cualquier Origin con credenciales. CORS no sustituye autenticación ni bloquea clientes no navegador. |
| HTTP-03 | Sí | Caché del proxy/CDN y aplicación no mezcla sesiones ni almacena respuestas privadas públicamente; revisar Cache-Control/Vary según rutas y cookies. Assets públicos pueden usar caché larga. |
| HTTP-04 | No | Métodos y límites de body/tiempo adecuados; mensajes de error genéricos sin stack, rutas internas, SQL ni secretos. Banners innecesarios minimizados sin considerarlos control principal. |

## AUTH — Sesiones, cuentas y autorización

| ID | E | Comprobación y resultado esperado |
|---|---|---|
| AUTH-01 | Sí | Autenticación real en acciones privadas; autorización por rol en servidor, elevación de rol no controlable por el cliente. Revisar APIs, no solo navegación. |
| AUTH-02 | Sí | Aislamiento entre usuarios/tenants en lecturas y escrituras (IDOR/BOLA). Pruebas con cuentas A/B y objetos sintéticos, no registros ajenos de producción. |
| AUTH-03 | Sí | Sesiones Secure/HttpOnly/SameSite apropiados, dominio/path mínimos, renovación al login, logout/revocación y expiración. Cookie CSRF legible por JS puede ser intencional. |
| AUTH-04 | Sí | CSRF en mutaciones con cookies, validación Origin/Referer cuando corresponda. Para tokens no automáticos, justificar aplicabilidad; CORS por sí solo no cubre CSRF. |
| AUTH-05 | Sí | Contraseñas con hash adecuado, recuperación sin fugas/tokens reutilizables, rate limits en login/recuperación y operaciones caras. MFA/acceso reforzado en administración según riesgo. |

## APP — Configuración y superficie de aplicación

| ID | E | Comprobación y resultado esperado |
|---|---|---|
| APP-01 | Sí | Modo producción efectivo, debug y herramientas de desarrollo no públicas, build correcto. Ausencia de `phpinfo`, paneles de pruebas y credenciales semilla accesibles. |
| APP-02 | Sí | Validación de entradas, queries parametrizadas, salida escapada y límites de recursos en endpoints expuestos; revisar puntos de SQL/command injection, XSS, path traversal y SSRF. La lectura de código es evidencia estática, no un pentest completo. |
| APP-03 | Sí | Límites/autenticación entre servicios, healthchecks mínimos, endpoints internos y OpenAPI/docs restringidos cuando revelen una API privada; no clasificar documentación pública intencional como fallo automático. |
| APP-04 | No | Webhooks firmados/replay, WebSocket Origin/autorización, pagos o integraciones si existen. No inventar módulos ausentes. |

## SUPPLY — Dependencias y entrega

| ID | E | Comprobación y resultado esperado |
|---|---|---|
| SUPPLY-01 | Sí | Runtimes, framework, servidor y SO soportados y parcheados. Auditoría de dependencias de producción; mapear advisory/CVE, versión instalada, ruta de uso y fix disponible. No inventar CVEs desde un banner. |
| SUPPLY-02 | No | Lockfiles/build reproducible, separación dev/runtime, imágenes sin secretos, tags/digests y procedencia según pipeline; CI con permisos mínimos y protección de despliegue. Dependencias de build también pueden comprometer artefactos. |
| SUPPLY-03 | No | Contenedores sin privileged ni docker.sock expuesto, usuario no root, capabilities/mounts limitados, filesystem de solo lectura donde sea viable. |

## OPS — Datos, continuidad y detección

| ID | E | Comprobación y resultado esperado |
|---|---|---|
| OPS-01 | Sí | DB/storage privados, credenciales de app con privilegios mínimos, separación de entornos y transporte seguro donde corresponda. Buckets/volúmenes y copias sin lectura pública. |
| OPS-02 | Sí | Para datos persistentes: backups protegidos, retención definida y evidencia de restauración en entorno aislado. Un job “verde” no prueba recuperación. |
| OPS-03 | No | Logs de seguridad sin secretos/datos innecesarios, rotación/retención/acceso, seguimiento de errores y alertas operativas para fallos críticos. |
| OPS-04 | No | Workers/scheduler supervisados, reinicio/healthchecks/límites/timeouts, tareas sin solapamiento y privilegios mínimos. Fallos no dejan colas expuestas o recursos ilimitados. |
| OPS-05 | No | Runbook de incidente, caducidad de certificados, rotación/revocación, responsables, rollback y revalidación de configuración tras releases. |

## Fuentes para resolver dudas

Consultar documentación vigente cuando un control dependa de versión o arquitectura:

- OWASP WSTG: https://owasp.org/www-project-web-security-testing-guide/
- OWASP ASVS: https://owasp.org/www-project-application-security-verification-standard/
- OWASP Cheat Sheets: https://cheatsheetseries.owasp.org/
- Laravel deployment: https://laravel.com/docs/deployment
- MDN HTTP security: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers

Citar la versión/fecha consultada al usar una recomendación específica. No atribuir
cumplimiento ASVS a este checklist ni fijar versiones de soporte a partir de memoria.
