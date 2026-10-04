# Informe de validación de seguridad web

## 1. Dictamen ejecutivo

- **Dictamen: VALIDACIÓN INCOMPLETA.** La evidencia disponible no permite dar el visto bueno para publicar en producción. No hay vulnerabilidades bloqueantes confirmadas, pero siguen pendientes controles esenciales.
- **Entorno / stack:** staging; Laravel, React y FastAPI. Proyecto, versiones, release y commit no aportados.
- **Fecha del informe:** 2026-10-03 (fecha de la sesión). Hora y zona horaria no disponibles; fecha de captura de las evidencias desconocida.
- **Alcance:** evaluación offline del escenario 1, exclusivamente sobre E1–E5. URL, hosts, proveedor, CDN, document root y topología no identificados. Producción sin evidencia.
- **Modo y accesos:** evidencias aportadas sin reproducción; sin credenciales ni posición externa de prueba identificada. No se ejecutaron comandos, peticiones, pruebas ni lecturas de archivos del proyecto ajenos a los recursos de la skill y su evaluación.
- **Bloqueos confirmados:** ninguno. Impiden aprobar la publicación las lagunas esenciales de red, archivos/configuración efectiva, HTTPS, autorización, datos y dependencias detalladas abajo.
- **Siguiente acción:** obtener evidencias sanitizadas del despliegue objetivo y completar los controles esenciales antes de reconsiderar la publicación.

El `200` de `/.env` corresponde al fallback SPA observado, no a una fuga demostrada. El listener `0.0.0.0:8090` no demuestra acceso desde Internet. La ausencia de HttpOnly en `XSRF-TOKEN` es compatible con su lectura por la SPA y no constituye un fallo por sí misma. `.env.example` no acredita la configuración efectiva de staging ni de producción.

Este dictamen se limita a la evidencia aportada: no es una certificación ni garantiza ausencia de vulnerabilidades.

## 2. Cobertura y metodología

Se recorren los 34 IDs del checklist; FILE-02 y AUTH-03 se desglosan para separar resultados observados de componentes pendientes. Los conteos siguientes corresponden a las **36 filas** de la matriz, sin duplicar controles padre.

| Total controles/subcontroles | VALIDADO | FALLA | NO VERIFICADO | NO APLICA |
|---:|---:|---:|---:|---:|
| 36 | 2 | 0 | 34 | 0 |

| Hallazgos CRÍTICOS | ALTOS | MEDIOS | BAJOS | INFORMATIVOS |
|---:|---:|---:|---:|---:|
| 0 | 0 | 0 | 0 | 0 |

Las carencias de evidencia son pendientes de validación, no vulnerabilidades confirmadas. No se usa NO APLICA para suplir datos de arquitectura ausentes.

**Registro de evidencia:** toda procede del escenario simulado de staging; sin fecha de captura, host concreto ni verificación independiente. Los métodos descritos son los aportados, no ejecuciones de esta revisión; no hay exit codes disponibles ni aplicables a estas lecturas narrativas.

| ID | Método referido | Resultado aportado e interpretación |
|---|---|---|
| E1 | Comparación de respuestas GET | `/.env`: `200 text/html`, mismo hash y longitud que `/ruta-inexistente-control` y el shell SPA, sin variables ni secretos. Evidencia de fallback SPA en esa respuesta; no de protección universal de archivos. |
| E2 | Información de firewall del host | Permite TCP 443 desde Internet. Security group y reglas IPv6 no disponibles; no acredita conectividad externa, TLS ni política completa. |
| E3 | Información de listener | `0.0.0.0:8090`: enlace a todas las interfaces IPv4. Protocolo de transporte no explicitado; sin prueba externa ni datos NAT/proxy. |
| E4 | Atributos de cookies | `laravel_session`: Secure, HttpOnly, SameSite=Lax. `XSRF-TOKEN`: Secure, SameSite=Lax, sin HttpOnly; lectura por JS intencional en el flujo SPA. No se aportan valores de cookies. |
| E5 | Declaración en archivo de ejemplo | `.env.example` declara `APP_DEBUG=false`. No hay configuración efectiva ni acceso a producción. |

## 3. Puertos y servicios

Mapa provisional: navegador → CDN/proxy **desconocido** → web/API Laravel/React → FastAPI → datos/almacenamiento **desconocidos**. Las conexiones y fronteras de confianza no están acreditadas.

| Activo / servicio | Puerto / protocolo / IPv4-IPv6 | Bind / publicación | Acceso esperado | Firewall host/proveedor | Prueba externa y origen | Estado / evidencia |
|---|---|---|---|---|---|---|
| Web / HTTPS previsto | 443/TCP; familias efectivas no determinadas | Listener y publicación desconocidos | HTTPS público | Host permite entrada desde Internet; proveedor e IPv6 desconocidos | Ninguna aportada | NO VERIFICADO; E2 solo acredita la regla declarada |
| Motor FastAPI / listener referido | 8090; transporte no explicitado; IPv4 | `0.0.0.0`; NAT, contenedores y proxy desconocidos | Servicio interno, accesible solo por consumidores autorizados | Reglas para 8090 desconocidas | Ninguna aportada | NO VERIFICADO; E3 no prueba exposición externa ni aislamiento |
| HTTP, administración, datos, caché, desarrollo y otros servicios | Inventario TCP/UDP e IPv4/IPv6 pendiente | Desconocidos | Solo servicios justificados; administración restringida y datos internos | Desconocido | Ninguna aportada | NO VERIFICADO; inventariar antes de seleccionar puertos concretos |

No se infieren listeners adicionales de los puertos habituales del stack. Falta distinguir inventario local, publicación, firewall del host/proveedor y alcance externo por cada familia IP; también falta descartar rutas públicas del proxy hacia el motor.

## 4. Archivos y rutas protegidas

| Archivo / ruta | Protección esperada | Estado HTTP / tipo / respuesta sanitizada | ACL / document root | Estado / evidencia |
|---|---|---|---|---|
| `/.env` | No entregar contenido privado | `200 text/html`; idéntico al shell SPA y control; sin secretos | Desconocidos | VALIDADO solo para ausencia de fuga en la respuesta aportada; E1 |
| `/ruta-inexistente-control` | Referencia para identificar fallback | Hash y longitud iguales a `/.env` y shell SPA; no se aporta estado/tipo por separado | Desconocidos | Referencia E1; no control adicional contabilizado |
| `/.env.production`, `/.env.bak` y otras variantes pertinentes | No entregar configuraciones privadas | Sin evidencia | Desconocidos | NO VERIFICADO |
| `/.git/HEAD`, `/.git/config` | Repositorio no accesible | Sin evidencia | Desconocidos | NO VERIFICADO |
| `/storage/logs/laravel.log`, dumps, backups y claves privadas | No accesibles por HTTP | Sin evidencia; ubicaciones reales pendientes | Desconocidos | NO VERIFICADO |
| `/database/database.sqlite`, copias y archivos `-wal`/`-shm`, si se usa SQLite | Datos y auxiliares privados | Sin evidencia; motor de datos desconocido | Desconocidos | NO VERIFICADO; confirmar aplicabilidad |
| `.env.example` (archivo citado, no URL probada) | No contener secretos; no sustituir configuración efectiva | E5 solo declara `APP_DEBUG=false`; sin respuesta HTTP | Desconocidos | NO VERIFICADO para exposición/contenido completo y runtime |

Las rutas pendientes son candidatas para una revisión futura acotada, no pruebas realizadas ni archivos cuya existencia se haya confirmado. E1 no permite extrapolar a variantes, otros hosts, alias, métodos o releases.

## 5. Matriz de validación

Método de esta matriz: contraste documental de E1–E5 con el checklist. **Sí*** indica esencial bajo la condición descrita, aún por confirmar. El guion en hallazgo significa que no existe desviación confirmada.

| ID | Control / activo | Esencial | Resultado esperado | Observado / evidencia o dato pendiente | Estado | Hallazgo |
|---|---|---|---|---|---|---|
| NET-01 | Inventario de red | Sí | Servicios y publicaciones justificados | E3 parcial; faltan inventario TCP/UDP, IPv6, procesos y NAT/contenedores | NO VERIFICADO | — |
| NET-02 | Aislamiento / firewalls | Sí | Entrada mínima y servicios internos aislados | E2/E3; faltan reglas completas host/proveedor y alcance externo por familia IP | NO VERIFICADO | — |
| NET-03 | Administración del host | Sí | Acceso restringido, robusto y con privilegios mínimos | Faltan canales administrativos, allowlists/VPN, llaves/MFA y recuperación | NO VERIFICADO | — |
| NET-04 | Salida / DNS | No | Destinos y timeouts acotados; DNS coherente | Faltan integraciones, política de salida y registros DNS | NO VERIFICADO | — |
| FILE-01 | Document root / routing | Sí | Solo artefactos públicos; Laravel en `public/` | Faltan root, alias, reglas, tratamiento de PHP y listing | NO VERIFICADO | — |
| FILE-02a | Respuesta de `/.env` | Sí | No entregar contenido privado | E1: fallback SPA, sin variables ni secretos, comparado con control | VALIDADO | — |
| FILE-02b | Resto de archivos privados | Sí | Ninguna ruta privada entrega contenido | Faltan variantes, Git, logs, claves, datos y backups por host | NO VERIFICADO | — |
| FILE-03 | Gestión de secretos | Sí | Secretos privados, separados por entorno y rotables | Faltan evidencias de repo/build/logs/CI y gestión; no se requieren valores | NO VERIFICADO | — |
| FILE-04 | ACL / usuario de servicio | Sí | Permisos mínimos y escritura acotada | Faltan propietarios, ACL y usuario web; storage/cache/datos sin revisar | NO VERIFICADO | — |
| FILE-05 | Uploads / source maps | No | Uploads sin ejecución y artefactos revisados | Funcionalidad y configuración desconocidas | NO VERIFICADO | — |
| TLS-01 | Certificado / protocolo | Sí | Certificado válido, TLS moderno y renovación | E2 no prueba HTTPS; faltan cadena, nombre, fechas, protocolos y renovación | NO VERIFICADO | — |
| TLS-02 | Redirección / transporte | Sí | HTTPS y confidencialidad en todos los saltos pertinentes | Faltan comportamiento HTTP y topología edge/origen | NO VERIFICADO | — |
| TLS-03 | Trusted proxies / hosts | Sí | Confianza restringida y esquema/IP correctos | Faltan configuración efectiva y tratamiento de cabeceras reenviadas | NO VERIFICADO | — |
| TLS-04 | HSTS / mixed content | No | Política compatible con HTTPS completo | Faltan cabeceras, recursos cargados y alcance de subdominios | NO VERIFICADO | — |
| HTTP-01 | Cabeceras defensivas | No | CSP/framing/nosniff y políticas adecuadas | Sin cabeceras aportadas | NO VERIFICADO | — |
| HTTP-02 | CORS | Sí | Orígenes concretos y credenciales controladas | Sin configuración ni respuestas de CORS | NO VERIFICADO | — |
| HTTP-03 | Caché privada | Sí | No mezclar sesiones ni publicar datos privados | Faltan reglas CDN/proxy/app y Cache-Control/Vary | NO VERIFICADO | — |
| HTTP-04 | Métodos / errores / límites | No | Límites adecuados y errores sin detalles internos | Sin evidencia de métodos, tamaños, tiempos ni errores | NO VERIFICADO | — |
| AUTH-01 | Autenticación / roles | Sí | Acciones privadas y administrativas protegidas en servidor | Faltan contratos y pruebas de permisos por rol | NO VERIFICADO | — |
| AUTH-02 | Propiedad de objetos | Sí | Usuarios aislados en lectura y escritura | Faltan pruebas con cuentas A/B y objetos sintéticos | NO VERIFICADO | — |
| AUTH-03a | Atributos de cookies observados | Sí | Sesión Secure/HttpOnly/SameSite; CSRF legible por la SPA | E4 cumple esos atributos; no HttpOnly en XSRF-TOKEN es intencional | VALIDADO | — |
| AUTH-03b | Alcance y ciclo de sesión | Sí | Dominio/path mínimos, renovación, revocación y caducidad | E4 no cubre estos componentes ni compatibilidad con topología real | NO VERIFICADO | — |
| AUTH-04 | CSRF | Sí | Mutaciones con cookies rechazan CSRF inválido/ausente | Cookie CSRF no prueba enforcement; faltan pruebas funcionales aisladas | NO VERIFICADO | — |
| AUTH-05 | Cuentas / abuso | Sí | Hash seguro, recuperación robusta y rate limits | Sin evidencia de contraseñas, recuperación, límites o refuerzo administrativo | NO VERIFICADO | — |
| APP-01 | Runtime / build | Sí | Producción efectiva, debug desactivado y build correcto | E5 es solo ejemplo; faltan runtime/cache, build y superficie de desarrollo | NO VERIFICADO | — |
| APP-02 | Entradas / salidas | Sí | Validación, parametrización, escape y límites | Sin revisión de endpoints ni evidencia de controles | NO VERIFICADO | — |
| APP-03 | Frontera entre servicios | Sí | Motor interno y acceso de servicio controlado | E3 no acredita aislamiento, autenticación, transporte ni rutas proxy | NO VERIFICADO | — |
| APP-04 | Integraciones especiales | No | Controles según webhooks/WebSocket/pagos existentes | Existencia de módulos desconocida; confirmar aplicabilidad | NO VERIFICADO | — |
| SUPPLY-01 | Soporte / vulnerabilidades | Sí | Versiones soportadas y auditoría de dependencias evaluada | Faltan versiones instaladas y resultados de auditoría | NO VERIFICADO | — |
| SUPPLY-02 | Build / CI | No | Entrega reproducible, sin secretos y con permisos mínimos | Sin lockfiles, procedencia ni configuración de CI aportados | NO VERIFICADO | — |
| SUPPLY-03 | Contenedores | No | Privilegios, mounts y capabilities mínimos | Uso de contenedores desconocido | NO VERIFICADO | — |
| OPS-01 | Datos / almacenamiento | Sí | Acceso privado, privilegios mínimos y separación de entornos | Faltan motor, almacenamiento, credenciales por rol y transporte | NO VERIFICADO | — |
| OPS-02 | Recuperación | Sí* | Backups privados y restauración aislada demostrada si hay persistencia | Persistencia, política y restauración sin evidencia | NO VERIFICADO | — |
| OPS-03 | Logs / detección | No | Logs protegidos, minimizados y con alertas operativas | Sin evidencia de acceso, retención, redacción ni detección | NO VERIFICADO | — |
| OPS-04 | Workers / scheduler | No | Supervisión, límites y no solapamiento | Existencia y configuración desconocidas | NO VERIFICADO | — |
| OPS-05 | Incidentes / rollback | No | Responsables, recuperación y revalidación definidos | Sin runbook aportado | NO VERIFICADO | — |

## 6. Hallazgos priorizados

**No hay hallazgos de vulnerabilidad confirmados con E1–E5.** No corresponde inventar severidades ni acciones correctivas como si se hubiese demostrado una fuga, exposición del motor o debug activo. Los dos subcontroles validados son resultados limitados a las respuestas aportadas de staging; no validan el despliegue completo ni producción.

Riesgos pendientes de resolver: exposición potencial de servicios/archivos, configuración inadecuada del runtime y posibles fallos de autorización o protección de datos. Son hipótesis que requieren evidencia, no incidentes constatados. No se ha corregido ni cerrado ningún hallazgo.

## 7. Plan de corrección y revalidación

P1 significa **antes del visto bueno para publicar**, no una severidad de vulnerabilidad. P2 corresponde a completar controles no esenciales y decidir su tratamiento. Responsables propuestos, sin asignación confirmada. Todas las acciones son futuras.

| Prioridad | Hallazgo / pendiente | Acción | Responsable propuesto | Verificación de cierre | Dependencias / rollback |
|---|---|---|---|---|---|
| P1 | Red, motor y administración | Documentar topología, inventario, NAT/proxy, reglas host/proveedor IPv4/IPv6 y acceso administrativo | Infraestructura | Evidencia externa desde origen identificado y revisión interna concordantes: motor y administración limitados a consumidores autorizados | Acceso autorizado; lectura sin rollback. Cambios posteriores con respaldo de reglas y vía administrativa de recuperación |
| P1 | Archivos, secretos y runtime | Aportar root/alias/ACL y configuración efectiva sanitizada; revisar variantes privadas, build y gestión de secretos | DevOps + backend | Solo artefactos públicos; rutas privadas sin contenido sensible; entorno/debug/cache efectivos documentados; secretos ausentes del frontend | No entregar claves ni cookies. Cualquier cambio posterior debe conservar configuración/release anterior y repetir pruebas |
| P1 | HTTPS, CORS y caché | Revisar dominio objetivo, TLS, saltos de proxy, trusted hosts/proxies, CORS y respuestas privadas | Infraestructura + backend | Certificado/transporte válidos, orígenes acotados y caché sin mezcla de sesiones | Topología y URL necesarias; cambios futuros con reversión de configuración y prueba de sesiones |
| P1 | Sesión, CSRF y autorización | Usar cuentas/objetos sintéticos en entorno aislado; comprobar roles, propiedad, sesión, recuperación y límites | Backend + QA | Accesos indebidos y CSRF inválido rechazados; usuarios aislados; renovación/revocación y límites demostrados | No ejecutar operaciones reales ni trabajos de ingestión; pruebas aisladas con limpieza de fixtures |
| P1 | Entradas, dependencias y datos | Revisar endpoints, versiones/auditorías, privilegios de datos y restauración aislada si hay persistencia | Desarrollo + operaciones | Riesgos de dependencias evaluados; controles de entrada/salida acreditados; datos privados y restauración documentada | Auditorías sin instalar ni aplicar fixes automáticos; restauración fuera del entorno activo |
| P2 | Controles complementarios | Completar DNS/salida, cabeceras, uploads, CI, contenedores, logs, workers y runbooks según arquitectura | Desarrollo + operaciones | Evidencia por ID o NO APLICA justificado; tratamiento de riesgos residuales con responsable y plazo | Confirmar módulos existentes; cambios operativos sujetos a plan específico de reversión |

## 8. Pendientes, excepciones y riesgos residuales

| Control no verificado / exclusión | Motivo | Acceso o evidencia necesaria | Próxima prueba |
|---|---|---|---|
| Producción y trazabilidad | Solo escenario staging; host/release/fechas desconocidos | URL/hosts autorizados, versiones, commit/release, fecha/zona de capturas y topología del objetivo | Vincular cada resultado al despliegue que se desea publicar y repetir allí los controles pertinentes |
| NET-01–04, APP-03 | E2/E3 incompletas | Listeners, publicaciones, reglas host/proveedor IPv4/IPv6, DNS, proxy y administración | Contrastar configuración y alcance desde red externa identificada; verificar ausencia de acceso público al motor |
| FILE-01, FILE-02b, FILE-03–05, APP-01 | E1 limitada a una ruta; E5 no es runtime | Root/alias, ACL, usuario de servicio, configuración efectiva sanitizada, build y política de secretos/uploads | Muestrear rutas privadas comparando con control SPA; revisar exposición y runtime sin capturar secretos |
| TLS-01–04, HTTP-01–04 | Sin pruebas TLS ni cabeceras | Dominio, certificados/renovación, proxies, cabeceras, CORS y caché | Validar TLS y saltos; revisar orígenes permitidos, caché privada y recursos HTTPS |
| AUTH-01–02, AUTH-03b, AUTH-04–05, APP-02 | E4 solo atributos de cookies | Cuentas A/B y roles, objetos sintéticos, reglas del servidor y sesión | Pruebas aisladas de permisos, propiedad, CSRF, ciclo de sesión, límites y validación de entradas |
| APP-04, SUPPLY-01–03 | Integraciones, versiones y pipeline desconocidos | Inventario de módulos, dependencias/versiones, auditorías, CI y contenedores si existen | Evaluar resultados reales y aplicabilidad; no asumir cero vulnerabilidades por ausencia de auditoría |
| OPS-01–05 | Datos y operación sin evidencia | Arquitectura de persistencia, ACL/roles, backups, restauración, logs, workers y runbook | Verificar privacidad/privilegios y restauración aislada; revisar controles operativos aplicables |

No constan excepciones aceptadas, controles compensatorios ni aceptación de riesgos. La falta de evidencia impide cuantificar exposición y riesgo residual.

**Criterios para reconsiderar el dictamen:** identificar el despliegue objetivo; aportar evidencia suficiente para todos sus controles esenciales aplicables, incluidos los componentes pendientes de FILE-02 y AUTH-03; justificar cualquier NO APLICA con arquitectura; resolver y revalidar todo fallo esencial o hallazgo alto/crítico que aparezca. Con esenciales validados y únicamente pendientes no esenciales con plan concreto, podrá corresponder APTO CON CONDICIONES; sin hallazgos aplicables pendientes, APTO EN EL ALCANCE REVISADO. Con E1–E5 únicamente, se mantiene **VALIDACIÓN INCOMPLETA**.
