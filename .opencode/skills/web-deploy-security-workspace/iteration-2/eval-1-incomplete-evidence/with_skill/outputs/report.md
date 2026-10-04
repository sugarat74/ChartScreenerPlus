# Informe de validación de seguridad web

## 1. Dictamen ejecutivo

**VALIDACIÓN INCOMPLETA. No se puede aprobar la publicación con la evidencia disponible.** No hay un fallo bloqueante confirmado, pero numerosos controles esenciales siguen sin verificar.

- **Evaluación:** escenario 1, iteración 2; simulación offline, no auditoría real del despliegue.
- **Entorno y stack aportados:** staging, Laravel/React/FastAPI. Proyecto, release, commit y versiones no indicados.
- **Elaboración:** 2026-10-04, según la fecha del entorno de esta sesión; hora y zona horaria no disponibles. **Captura de evidencias:** fecha, hora y zona desconocidas.
- **Objetivos y alcance:** exclusivamente E1–E5 del escenario. No se conocen URL, hosts, proveedor, CDN/proxy ni document root. Sin evidencia de producción.
- **Modo y accesos:** análisis de evidencias aportadas, sin reproducción; sin credenciales ni posición de prueba identificada. No se ejecutaron comandos, pruebas ni accesos de red. No se inspeccionaron archivos del proyecto para obtener evidencia adicional.
- **Bloqueos confirmados:** ninguno. La aprobación queda pendiente, especialmente por NET-01–03, FILE-01/02b/03/04, TLS-01–03, AUTH y APP-01/03.
- **Siguiente acción:** identificar el despliegue objetivo y aportar evidencia sanitizada de sus controles esenciales, con fecha, release y origen de cada comprobación.

E1 muestra un **fallback SPA**, no una fuga de `.env`. E3 acredita un listener IPv4, no su exposición externa ni la identidad del proceso. E4 no demuestra un fallo por faltar HttpOnly en `XSRF-TOKEN`: esa cookie puede necesitar lectura por JavaScript, pero el diseño y funcionamiento CSRF concretos no están acreditados. E5 no valida el debug efectivo.

Este dictamen se limita al alcance observado; no es una certificación ni garantiza ausencia de vulnerabilidades.

## 2. Cobertura y metodología

Método de todos los controles: contraste documental de E1–E5 con el checklist. No hubo inspección estática del código ni pruebas propias. Las acciones futuras no se contabilizan como evidencia.

| Total de filas de control | VALIDADO | FALLA | NO VERIFICADO | NO APLICA |
|---:|---:|---:|---:|---:|
| 36 | 2 | 0 | 34 | 0 |

Se cubren los 34 IDs del checklist; FILE-02 y AUTH-03 se dividen en dos subcontroles cada uno. Recuento por familia: NET 4, FILE 6, TLS 4, HTTP 4, AUTH 6, APP 4, SUPPLY 3, OPS 5: **36 filas = 2 validadas + 34 no verificadas**. No se contabilizan los controles padre otra vez.

| Hallazgos CRÍTICOS | ALTOS | MEDIOS | BAJOS | INFORMATIVOS |
|---:|---:|---:|---:|---:|
| 0 | 0 | 0 | 0 | 0 |

**Registro de evidencia.** Fuente común: relato suministrado en el escenario de staging, sin capturas originales, fecha ni identidad del recolector. Los métodos siguientes son los referidos en el relato; no se ejecutaron aquí. No se aportan comandos ni códigos de salida.

| ID | Método referido | Resultado sanitizado y límite |
|---|---|---|
| E1 | GET y comparación de respuestas | `/.env` devuelve `200 text/html`, idéntico en hash y longitud a `/ruta-inexistente-control` y al shell SPA, sin variables ni secretos. No acredita otras rutas/hosts. |
| E2 | Información del firewall del host | Permite TCP 443 desde Internet; security group y reglas IPv6 no disponibles. No demuestra listener, TLS o conexión externa. |
| E3 | Información de listener | `0.0.0.0:8090`; sin prueba externa ni datos NAT/proxy. Proceso y protocolo no especificados. |
| E4 | Atributos de cookies | `laravel_session`: Secure, HttpOnly, SameSite=Lax. `XSRF-TOKEN`: Secure, SameSite=Lax, sin HttpOnly. Sin valores de cookies, alcance ni pruebas de sesión/CSRF. |
| E5 | Declaración en archivo de ejemplo | `.env.example`: `APP_DEBUG=false`. Sin configuración efectiva ni acceso a producción. |

## 3. Puertos y servicios

Mapa por completar: navegador → posible CDN/proxy → web/API → servicios internos → datos. El stack está declarado, pero no se han acreditado las conexiones, procesos, publicaciones ni fronteras de confianza.

| Activo / servicio | Puerto / protocolo / IPv4-IPv6 | Bind / publicación | Acceso esperado | Firewall host/proveedor | Prueba externa y origen | Estado / evidencia |
|---|---|---|---|---|---|---|
| Regla de entrada; servicio no identificado | 443/TCP; familia no especificada | Listener y publicación desconocidos | Si sirve HTTPS público, acceso justificado y TLS válido | Host permite desde Internet; proveedor e IPv6 desconocidos | No aportada | NO VERIFICADO para servicio/alcance; E2 |
| Listener; proceso no identificado | 8090; protocolo no aportado; IPv4 | `0.0.0.0`; publicación NAT/contenedor/proxy desconocida | Depende de identificar su función; si es interno, restringido a consumidores autorizados | Política para 8090 desconocida | No aportada | NO VERIFICADO para aislamiento; E3 |
| FastAPI declarado en el stack | Puerto, transporte y familias desconocidos | Desconocidos | Frontera de servicio según topología y finalidad | Desconocido | No aportada | NO VERIFICADO; vincularlo con 8090 sería una hipótesis |
| Administración, datos y otros servicios | Inventario TCP/UDP e IPv4/IPv6 pendiente | Desconocidos | Exposición mínima con propósito documentado | Desconocido | No aportada | NO VERIFICADO; no se presume su existencia ni sus puertos |

Un bind a todas las interfaces IPv4 no prueba acceso desde Internet. Tampoco la regla de 443 acredita HTTPS. Faltan inventario completo, contenedores/NAT, rutas proxy, firewall de ambas capas, IPv6 y pruebas externas desde un origen identificado; una eventual CDN tampoco representaría todo el origen.

## 4. Archivos y rutas protegidas

Las rutas pendientes son propuestas de comprobación futura acotada; no se afirma su existencia.

| Archivo / ruta | Protección esperada | Estado HTTP / tipo / respuesta sanitizada | ACL / document root | Estado / evidencia |
|---|---|---|---|---|
| `/.env` | No entregar contenido privado | `200 text/html`, shell SPA sin variables ni secretos | Desconocidos | VALIDADO únicamente para no divulgación en esa respuesta; E1 |
| `/ruta-inexistente-control` | Referencia para distinguir fallback | Hash/longitud iguales a `/.env` y shell; estado/tipo propios no detallados | Desconocidos | Referencia E1, no control adicional |
| `/.env.production`, `/.env.bak` | No entregar variantes privadas | Sin evidencia | Desconocidos | NO VERIFICADO |
| `/.git/HEAD`, `/.git/config` | No entregar repositorio privado | Sin evidencia | Desconocidos | NO VERIFICADO |
| `/storage/logs/laravel.log`, `/backup.zip`, dumps y claves | No entregar datos privados | Sin evidencia; ubicaciones reales pendientes | Desconocidos | NO VERIFICADO |
| Archivos de base de datos y auxiliares, si aplica | Datos/copias fuera del acceso público | Motor y rutas desconocidos; si SQLite, incluir `-wal`/`-shm` | Desconocidos | NO VERIFICADO; confirmar arquitectura |
| `.env.example` (archivo referido, no URL probada) | Ejemplo sin secretos; no sustituye runtime | E5 solo aporta `APP_DEBUG=false`; no hay evidencia HTTP | Desconocidos | NO VERIFICADO para contenido completo/exposición |

E1 permite identificar fallback SPA en vez de una fuga, login o WAF en la respuesta descrita. No demuestra una regla general de denegación, la corrección del document root ni la protección de variantes y otros hosts.

## 5. Matriz de validación

**E:** esencial si aplica. Para módulos o persistencia desconocidos, se conserva NO VERIFICADO hasta confirmar aplicabilidad. Los guiones en hallazgo indican ausencia de desviación confirmada.

| ID | Control / activo | E | Resultado esperado | Observado / evidencia o carencia | Estado | Hallazgo |
|---|---|---|---|---|---|---|
| NET-01 | Inventario de superficie | Sí | Listeners/publicaciones identificados y justificados | E3 parcial; faltan procesos, TCP/UDP, IPv6 y publicaciones | NO VERIFICADO | — |
| NET-02 | Firewall / aislamiento | Sí | Entrada mínima; servicios internos aislados | E2/E3; faltan reglas completas, proveedor, IPv6 y alcance externo | NO VERIFICADO | — |
| NET-03 | Administración | Sí | Acceso restringido y robusto, privilegios mínimos | Sin canales, llaves/MFA, allowlists ni recuperación acreditados | NO VERIFICADO | — |
| NET-04 | Salida / DNS | No | Destinos/timeouts limitados y DNS coherente | Sin integraciones, política de salida ni registros | NO VERIFICADO | — |
| FILE-01 | Root / routing | Sí | Solo artefactos públicos; sin fuentes PHP ni listing | Sin root, alias, reglas o tratamiento de PHP | NO VERIFICADO | — |
| FILE-02a | Respuesta `/.env` | Sí | No divulgar contenido privado | E1: fallback SPA sin secretos comparado con control | VALIDADO | — |
| FILE-02b | Resto de rutas privadas | Sí | Variantes, Git, datos, logs y copias no accesibles | Sin evidencia ruta por ruta ni otros hosts | NO VERIFICADO | — |
| FILE-03 | Secretos | Sí | Fuera de repo/build/logs/CI; separados y rotables | Sin evidencia de almacenamiento, alcance ni rotación | NO VERIFICADO | — |
| FILE-04 | Permisos | Sí | ACL mínimas y usuario web sin privilegios excesivos | Sin propietarios, ACL ni usuarios de servicio | NO VERIFICADO | — |
| FILE-05 | Uploads / mapas fuente | No | Uploads sin ejecución y artefactos revisados | Existencia, configuración y contenido desconocidos | NO VERIFICADO | — |
| TLS-01 | HTTPS | Sí | Certificado válido, TLS moderno y renovación | E2 no acredita TLS; faltan certificado/protocolos/renovación | NO VERIFICADO | — |
| TLS-02 | Redirección / saltos | Sí | HTTPS y transporte confidencial según topología | Sin HTTP, CDN/origen ni enlaces internos acreditados | NO VERIFICADO | — |
| TLS-03 | Hosts / proxies confiables | Sí | Confianza acotada; esquema e IP correctos | Sin configuración efectiva ni tratamiento de cabeceras | NO VERIFICADO | — |
| TLS-04 | HSTS / contenido mixto | No | Política compatible y recursos HTTPS | Sin cabeceras, subdominios ni recursos observados | NO VERIFICADO | — |
| HTTP-01 | Cabeceras | No | CSP/framing/nosniff y políticas adecuadas | Sin cabeceras aportadas | NO VERIFICADO | — |
| HTTP-02 | CORS | Sí | Solo orígenes necesarios; credenciales controladas | Sin configuración ni respuestas CORS | NO VERIFICADO | — |
| HTTP-03 | Caché | Sí | Sin respuestas privadas públicas ni mezcla de sesiones | Sin reglas proxy/CDN/app ni Cache-Control/Vary | NO VERIFICADO | — |
| HTTP-04 | Métodos / límites / errores | No | Límites adecuados y errores sin detalles internos | Sin evidencia de métodos, límites o errores | NO VERIFICADO | — |
| AUTH-01 | Autenticación / roles | Sí | Acciones privadas y roles protegidos en servidor | Sin contratos ni pruebas de permisos | NO VERIFICADO | — |
| AUTH-02 | Propiedad | Sí | Aislamiento de usuarios en lectura/escritura | Sin pruebas A/B con objetos sintéticos | NO VERIFICADO | — |
| AUTH-03a | Flags de cookie de sesión | Sí | Secure y HttpOnly presentes; SameSite explícito | E4: `laravel_session` tiene Secure, HttpOnly y Lax | VALIDADO | — |
| AUTH-03b | Alcance / ciclo / adecuación | Sí | Dominio/path mínimos, renovación, revocación, expiración y política acorde al flujo | E4 no cubre ciclo ni topología; intención de cookie CSRF no acreditada | NO VERIFICADO | — |
| AUTH-04 | CSRF | Sí | Mutaciones con cookies rechazan solicitudes CSRF inválidas | E4 no prueba enforcement; XSRF sin HttpOnly puede ser necesario para JS | NO VERIFICADO | — |
| AUTH-05 | Cuentas / abuso | Sí | Hash seguro, recuperación robusta y límites | Sin evidencia de hash, tokens, rate limits ni refuerzo administrativo | NO VERIFICADO | — |
| APP-01 | Configuración / build | Sí | Runtime de producción, debug desactivado, build y superficie adecuados | E5 es un ejemplo; faltan runtime/cache/build y herramientas expuestas | NO VERIFICADO | — |
| APP-02 | Entradas / salidas | Sí | Validación, parametrización, escape y límites | Sin revisión ni pruebas de endpoints | NO VERIFICADO | — |
| APP-03 | Servicios internos | Sí | Fronteras, autenticación y transporte adecuados | Stack declarado; identidad de E3, endpoints y rutas proxy desconocidos | NO VERIFICADO | — |
| APP-04 | Integraciones especiales | No | Controles para webhooks/WebSocket/pagos si existen | Módulos y aplicabilidad desconocidos | NO VERIFICADO | — |
| SUPPLY-01 | Soporte / dependencias | Sí | Versiones soportadas y vulnerabilidades evaluadas | Sin versiones, inventario ni resultados de auditoría | NO VERIFICADO | — |
| SUPPLY-02 | Entrega / CI | No | Build reproducible, permisos mínimos y artefactos sin secretos | Sin lockfiles, procedencia ni configuración CI | NO VERIFICADO | — |
| SUPPLY-03 | Contenedores | No | Privilegios/mounts/capabilities mínimos | Uso y configuración desconocidos | NO VERIFICADO | — |
| OPS-01 | Datos | Sí | Almacenamiento privado, mínimos privilegios y separación | Sin arquitectura, roles, transporte ni políticas de acceso | NO VERIFICADO | — |
| OPS-02 | Backups / restauración | Sí, si persistencia | Copias protegidas y restauración aislada demostrada | Persistencia, retención y restauración desconocidas | NO VERIFICADO | — |
| OPS-03 | Logs / detección | No | Logs protegidos, minimizados y supervisados | Sin retención, acceso, redacción ni alertas acreditados | NO VERIFICADO | — |
| OPS-04 | Workers / scheduler | No | Supervisión, límites y ausencia de solapamiento | Existencia y operación desconocidas | NO VERIFICADO | — |
| OPS-05 | Incidentes | No | Runbook, responsables, rollback y revalidación | Sin documentación operativa aportada | NO VERIFICADO | — |

## 6. Hallazgos priorizados

**Ninguna vulnerabilidad confirmada con E1–E5.** No corresponde asignar severidad a lagunas de evidencia ni declarar debug activo, exposición externa del listener o fuga de secretos. Los dos subcontroles validados solo cubren resultados concretos aportados de staging.

No se requiere una corrección demostrada por estos datos. Se requiere completar la validación; los riesgos de exposición, autorización o pérdida de datos siguen sin poder determinarse. No hay hallazgos corregidos ni cerrados.

## 7. Plan de corrección y revalidación

Plan futuro, no ejecutado. **P1:** antes de aprobar publicación; **P2:** completar controles complementarios. Estas prioridades no son severidades. Los responsables son propuestas.

| Prioridad | Hallazgo / pendiente | Acción | Responsable propuesto | Verificación de cierre | Dependencias / rollback |
|---|---|---|---|---|---|
| P1 | Identidad y red | Identificar hosts, procesos, topología y propósito de 8090; revisar publicaciones y firewalls IPv4/IPv6 | Infraestructura | Inventario y reglas coherentes con pruebas externas de origen identificado; servicios internos y administración restringidos | Alcance autorizado; revisión de lectura. Si exige cambios, respaldar reglas y mantener vía de recuperación |
| P1 | Archivos y runtime | Revisar root/alias/ACL, rutas privadas, build, secretos y configuración efectiva sanitizada | DevOps + backend | Solo artefactos públicos, sin contenido privado; configuración efectiva de producción y caché coherentes | No solicitar valores secretos; cambios posteriores con configuración/release anterior y revalidación |
| P1 | TLS / HTTP / sesión | Revisar todos los saltos, proxies, CORS, caché, dominio/path y ciclo de sesión | Infraestructura + backend | TLS válido, confianza restringida, ausencia de caché privada pública y sesión correcta | URL/topología necesarias; cambios operativos con reversión documentada |
| P1 | Autorización / CSRF / entradas | Preparar cuentas y objetos sintéticos en entorno aislado y comprobar permisos, propiedad, CSRF y límites | Backend + QA | Rechazo de accesos y CSRF inválidos; ciclo de sesión y entradas/salidas protegidos | Confirmar aislamiento de datos; no disparar trabajos ni operaciones reales |
| P1 | Dependencias y datos | Aportar versiones/auditorías; revisar privacidad, privilegios y restauración si hay persistencia | Desarrollo + operaciones | Auditorías evaluadas, datos protegidos y recuperación demostrada en entorno aislado | No aplicar fixes automáticos; restauración separada del entorno activo |
| P2 | Resto del checklist | Confirmar módulos y revisar controles complementarios de la sección 8 | Desarrollo + operaciones | Evidencia por ID o NO APLICA motivado; riesgos residuales con responsable y plazo | Toda corrección operativa futura requiere prueba de cierre y rollback específico |

## 8. Pendientes, excepciones y riesgos residuales

| Control no verificado / exclusión | Motivo | Acceso o evidencia necesaria | Próxima prueba |
|---|---|---|---|
| Producción / trazabilidad | Solo staging y capturas sin fecha/host | URL/hosts autorizados, release, versiones y fecha/zona de evidencia del objetivo | Vincular resultados al despliegue que se publicará; no extrapolar staging |
| NET-01–03, APP-03 | E2/E3 parciales | Inventario, procesos, reglas host/proveedor, NAT/proxy, IPv6 y administración | Contrastar configuración con alcance externo; comprobar rutas públicas accidentales |
| FILE-01/02b/03/04, APP-01 | E1 limitada y E5 no efectiva | Root/alias/ACL, usuario web, build, secretos y runtime/cache sanitizados | Comprobar rutas privadas con control SPA y revisar configuración efectiva sin capturar secretos |
| TLS-01–03, HTTP-02/03 | Sin TLS/CORS/caché acreditados | Certificados, renovación, topología, trusted proxies/hosts, cabeceras y reglas | Verificar transporte completo, orígenes concretos y aislamiento de caché |
| AUTH-01/02/03b/04/05, APP-02 | Flags de cookies insuficientes | Contratos de permisos, flujo CSRF, cuentas A/B y objetos sintéticos | Verificar autorización, propiedad, CSRF, sesión, recuperación, rate limits y entradas en aislamiento |
| SUPPLY-01, OPS-01/02 | Sin versiones ni arquitectura de datos | Inventario y auditorías; motor/storage, privilegios, copias y retención | Evaluar vulnerabilidades reales, privacidad y restauración aislada si aplica |
| NET-04, FILE-05, TLS-04, HTTP-01/04, APP-04 | Sin información complementaria | DNS/salida, uploads/maps, HSTS/recursos, cabeceras/límites e integraciones existentes | Revisar controles según arquitectura; justificar aplicabilidad |
| SUPPLY-02/03, OPS-03/04/05 | Entrega y operación desconocidas | CI/build, contenedores, logs, workers/scheduler y runbook | Revisar permisos, secretos, supervisión, límites y recuperación operativa |

No constan excepciones aceptadas, plazos de excepción ni controles compensatorios. La ausencia de evidencia no demuestra ausencia de riesgo; tampoco justifica marcar NO APLICA.

**Para reconsiderar la publicación:** identificar el objetivo y acreditar todos los controles esenciales aplicables, incluidos los componentes pendientes de FILE-02 y AUTH-03; justificar cualquier exclusión con arquitectura; corregir y revalidar cualquier fallo esencial o hallazgo alto/crítico que se descubra. Solo con esenciales validados podrá emitirse APTO CON CONDICIONES si quedan riesgos no esenciales con plan concreto, o APTO EN EL ALCANCE REVISADO si no quedan hallazgos aplicables pendientes. Hasta entonces: **VALIDACIÓN INCOMPLETA**.
