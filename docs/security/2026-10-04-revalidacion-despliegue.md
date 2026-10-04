# Revalidación de despliegue — Chartiko

## 1. Dictamen ejecutivo

- **Dictamen: VALIDACIÓN INCOMPLETA para la auditoría global.** Los bloqueos concretos SEC-001/002 del informe anterior están cerrados en el alcance comprobado y la publicación autorizada terminó correctamente. No se certifican controles pendientes.
- **Entorno/release observado:** producción, `/var/www/alphapulse/releases/65ec3b16ff`, commit de fusión de PR #1.
- **Fecha de captura:** 2026-10-04, aproximadamente 18:15–18:20 Europe/Madrid (UTC+02:00).
- **Alcance:** www.chartiko.com, VPS 51.222.158.148, flujo GitHub y repositorio. SSH como deploy, HTTP desde el equipo Windows, pruebas aisladas locales y evidencia de consola root aportada por el propietario.
- **Siguiente acción de auditoría:** verificar firewall/proveedor, política de backups/retención, advisories Python y CSRF autenticado. Las aceptaciones independientes de features siguen pendientes.

Este informe complementa, sin borrar el historial, `2026-10-04-141702-produccion.md`. Distingue revalidación operativa de auditoría completa.

## 2. Cobertura y evidencias

| Total controles | VALIDADO | FALLA | NO VERIFICADO | NO APLICA |
|---:|---:|---:|---:|---:|
| 34 | 11 | 0 | 20 | 3 |

Ningún hallazgo crítico/alto permanece confirmado en esta revalidación acotada; controles no verificados no se cuentan como aprobados. No se reevalúan todas las severidades históricas.

- **E-101:** init.ps1 exit 0: Laravel 154 tests/985 aserciones, frontend lint/build 133 módulos, Python 47 tests (warning Starlette existente). Pruebas locales con base aislada; no pruebas destructivas en producción.
- **E-102:** regresiones SSH pasan: seis checks de configuración/sintaxis y dos en vivo. Pin correcto aceptado; clave sustituida rechazada antes de autenticar. Usuario aporta desde consola proveedor huella `SHA256:/TrDxOLMZvEZTIV12P4CVrzxsYnJrry2hxw4l07znMM`, coincidente. Secret production actualizado con esa clave pública.
- **E-103:** tras ejecución root por el propietario, stat remoto: app/releases/shared/current/venv 750; código y .env 640; SQLite 660 en shared/database (directorio 2770); bootstrap/cache 2770/config.php 660. sudo NOPASSWD solo para restart engine/queue y reload PHP/Nginx; sin wildcard.
- **E-104:** SQLite quick_check ok. Backup adicional privado en `/home/deploy/chartiko-pre-release-20261004T154604Z`; copia abierta aisladamente, integrity_check ok y seis recuentos de tablas coincidentes con origen. No se publican datos personales ni contenido de backups.
- **E-105:** cuatro servicios activos. Engine/queue como www-data con Restart=always; engine TCP 8090 en loopback. Cron de aplicación presente: schedule:run cada minuto como www-data.
- **E-106:** caché efectiva sanitizada: env production, debug false, APP_KEY presente sin mostrarla, APP_URL HTTPS canónico. Nombre de SESSION_COOKIE preservado. Una regeneración temporal produjo config.php 600 y APIs 500; se corrigió a 660 y recargó PHP, restaurando Screener 200/guest privados 401 antes del release. El deploy definitivo también deja config.php 660.
- **E-107:** helper Nginx aplicado; configuración Certbot conservada. Map usa request_uri original; `/screener` devuelve X-Robots-Tag noindex, follow. La plantilla anterior con encabezado dentro de location podía perderlo tras fallback; corregida.
- **E-108:** PR #1 fusionada. [CI/deploy 37215973285](https://github.com/sugarat74/ChartScreenerPlus/actions/runs/37215973285) success; current coincide con 65ec3b16ff. Primer intento de workflow falló antes de jobs por runner.temp en job env; corregido mediante GITHUB_ENV en un step. CI definitivo pasó.
- **E-109:** HTTPS home 200 con Chartiko, canonical, JSON-LD y CTA Screener; robots 200 text/plain; sitemap 200 text/xml con una sola URL raíz. Screener/instrument NVDA 200 JSON; watchlist/screeners/admin ping guests 401 JSON. .env/.git HEAD 403.
- **E-110:** HTTP 301 a HTTPS; conexión HTTPS sin ignorar certificados. Cookies sanitizadas: XSRF Secure/SameSite=Lax; sesión Secure/HttpOnly/SameSite=Lax. No login ni mutaciones de usuarios reales.
- **E-111:** base de producción distinta de local: 68.136 Daily Bars, 0 Indicator Snapshots, 0 Signals después del release. No se lanzó ingestión/backfill como prueba de seguridad. La ausencia de Candidates debe tratarse como estado de datos, no como fallo de permisos/deploy.

## 3. Puertos y servicios

| Servicio | Bind/puerto TCP | Publicación esperada | Firewall host/proveedor | Estado |
|---|---|---|---|---|
| SSH | 0.0.0.0:22 y [::]:22 | Administración | NO VERIFICADO | SSH estricto funciona; allowlist/MFA pendientes |
| Nginx | 0.0.0.0:80/443 | Web público | NO VERIFICADO | HTTPS 200, HTTP 301 |
| Engine | 127.0.0.1:8090 | Solo interno | NO VERIFICADO | Loopback y health ok |
| DNS local | 127.0.0.53/54:53 | Solo local | NO VERIFICADO | Listener observado |
| PHP/queue | PHP-FPM/unidad systemd | Interno | NO VERIFICADO | Unidades activas; inventario UDP/procesos completo pendiente |

No se infiere aislamiento externo completo solo del bind; no se inspeccionó el firewall del proveedor.

## 4. Archivos protegidos

| Activo/ruta | HTTP/ACL observado | Estado |
|---|---|---|
| /.env | 403; archivo privado 640 | VALIDADO en ruta comprobada |
| /.git/HEAD | 403 | VALIDADO en ruta comprobada |
| Código/activación/venv | Directorios 750, ficheros 640; sin escritura de grupo | VALIDADO para modos observados |
| SQLite | Ruta privada dedicada, 660/2770; integridad ok | VALIDADO para permisos y almacenamiento |
| Variantes/backups/logs por HTTP | No repetidos en esta sesión; informe previo distinguió fallback SPA de exposición | NO VERIFICADO en esta nueva captura |

No se descargaron cuerpos sensibles. El 200 de robots/sitemap ahora corresponde a contenido real, no al fallback antiguo.

## 5. Matriz completa

| ID | Control | Esencial | Estado | Evidencia / pendiente |
|---|---|---|---|---|
| NET-01 | Inventario listeners | Sí | NO VERIFICADO | E-105 TCP parcial; UDP/procesos completo pendiente |
| NET-02 | Firewall/aislamiento externo | Sí | NO VERIFICADO | Falta host/proveedor |
| NET-03 | Administración reforzada | Sí | NO VERIFICADO | E-102/103 SSH/sudo; allowlist/MFA pendientes |
| NET-04 | Salida/DNS | No | NO VERIFICADO | No revalidado |
| FILE-01 | Document root/routing | Sí | VALIDADO | Configuración Nginx leída, SPA dist/API public; E-107/109 |
| FILE-02 | Archivos sensibles HTTP | Sí | NO VERIFICADO | E-109 dos rutas 403; variantes pendientes |
| FILE-03 | Secretos/build/rotación | Sí | NO VERIFICADO | No auditoría completa nueva |
| FILE-04 | Permisos mínimos | Sí | VALIDADO | E-103; alcance modos de código/shared/activación |
| FILE-05 | Uploads | No | NO APLICA | Sin módulo de uploads en MVP |
| TLS-01 | Certificado/TLS/renovación | Sí | NO VERIFICADO | E-110 HTTPS válido; renovación/protocolos completos no repetidos |
| TLS-02 | Redirección/transportes | Sí | VALIDADO | E-110 301 a HTTPS; engine loopback |
| TLS-03 | Proxies/hosts | Sí | NO VERIFICADO | APP_URL corregido; trusted hosts/proxies no revalidados |
| TLS-04 | HSTS/mixed content | No | NO VERIFICADO | No revalidado |
| HTTP-01 | Cabeceras de seguridad | No | NO VERIFICADO | Noindex es SEO; no sustituye CSP/anti-framing |
| HTTP-02 | CORS | Sí | NO VERIFICADO | No nueva prueba de orígenes |
| HTTP-03 | Caché privada | Sí | NO VERIFICADO | No login/usuarios reales |
| HTTP-04 | Métodos/límites/errores | No | NO VERIFICADO | 500 temporal corregido; límites completos pendientes |
| AUTH-01 | Auth/roles servidor | Sí | VALIDADO | E-101 suites aisladas; E-109 guards guest |
| AUTH-02 | Propiedad | Sí | VALIDADO | E-101 suites aisladas A/B; sin mutaciones productivas |
| AUTH-03 | Ciclo de sesión | Sí | NO VERIFICADO | E-110 atributos; login/logout/expiración productivos pendientes |
| AUTH-04 | CSRF | Sí | NO VERIFICADO | Pruebas locales; flujo autenticado productivo pendiente |
| AUTH-05 | Password/rate limits/MFA | Sí | NO VERIFICADO | No nueva revisión completa |
| APP-01 | Producción/debug/build | Sí | VALIDADO | E-106/108/109 |
| APP-02 | Entradas/SQL/salida | Sí | VALIDADO | Alcance estático y suites locales E-101; no pentest |
| APP-03 | API interna | Sí | VALIDADO | E-105 loopback y configuración sin proxy engine |
| APP-04 | Pagos/webhooks/WebSocket | No | NO APLICA | Módulos ausentes |
| SUPPLY-01 | Soporte/advisories | Sí | NO VERIFICADO | No auditoría Python ni revisión de parches completa |
| SUPPLY-02 | CI reproducible/confianza SSH | No | VALIDADO | E-102/108 pin y CI/deploy efectivo |
| SUPPLY-03 | Contenedores | No | NO APLICA | Despliegue systemd, sin contenedores |
| OPS-01 | DB/storage privados | Sí | VALIDADO | E-103/104 |
| OPS-02 | Backups/retención/restauración | Sí | NO VERIFICADO | E-104 restauración manual validada; política automatizada/retención pendiente |
| OPS-03 | Logs/detección | No | NO VERIFICADO | No revalidado |
| OPS-04 | Worker/scheduler | No | VALIDADO | E-105 supervisión/cron; sin disparar datos |
| OPS-05 | Incidente/rotación/rollback | No | NO VERIFICADO | Scripts/backups disponibles; runbook global pendiente |

## 6. Cierre de hallazgos concretos

- **SEC-001, cerrado en alcance observado:** escritura web sobre padres/código/venv retirada; SQLite separado. E-103/104 y revalidación después del release. Recuperación: conservar backup privado, alinear ruta SQLite/caché ante fallo; no restablecer escritura web sobre código/secretos.
- **SEC-002, cerrado:** huella independiente aportada por propietario, secret fijado, pin distinto rechazado y CI/deploy efectivo correcto. E-102/108. Rotación exige nueva verificación independiente; no retirar strict checking.
- **SEC-007, cerrado para URL efectiva:** APP_URL HTTPS canónico; cookie de sesión preservada. E-106.
- **SEC-009, cerrado para sudo esperado:** comandos exactos sin wildcard; despliegue efectivo usa esos permisos. E-103/108.

## 7. Plan y riesgos residuales

| Prioridad | Acción | Responsable | Cierre |
|---|---|---|---|
| P1 | Auditar firewall host/proveedor, SSH reforzado y parches/advisories | Operaciones | Reglas/listeners/alcance y auditorías reproducidas |
| P1 | Automatizar backup/retención y repetir restauración aislada | Operaciones | Evidencia de política y recuperación |
| P1 | Revalidar CSRF/caché/sesión autenticados con cuentas de prueba autorizadas | QA/operaciones | Mutaciones/caché aisladas correctas |
| P2 | Completar cabeceras, incidentes y variantes de archivos | Operaciones | Matriz pendiente cerrada sin romper SPA/API |
| Producto | Calcular Indicator Snapshots/Signals para barras ya almacenadas | Operador | Candidates presentes; no se ejecutó en esta revisión |

Indexación real, Search Console/Bing, citas GEO y QA visual/independiente de features no se deducen de la publicación. No se envió ninguna campaña ni mensaje externo.
