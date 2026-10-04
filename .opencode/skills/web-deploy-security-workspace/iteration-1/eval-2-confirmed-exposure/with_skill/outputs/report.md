# Informe de validación de seguridad web

## 1. Dictamen ejecutivo

- **Dictamen: NO APTO.** La publicación queda bloqueada por exposición de secretos, servicio interno público sin autenticación y autorización Admin defectuosa.
- **Proyecto / entorno / release:** objetivo ficticio `app.example.test`, producción Laravel/React; versiones, release y commit no aportados.
- **Fecha del informe:** 2026-10-03. Hora y zona no disponibles; fechas de captura de las evidencias no aportadas.
- **Alcance:** exclusivamente el escenario del eval id 2 y sus evidencias E1–E6. Es una evaluación offline, no una auditoría real del despliegue.
- **Modo y accesos:** evidencias aportadas sin reproducción. No se ejecutaron comandos, peticiones, pruebas, cambios operativos ni lecturas de otros archivos del proyecto. Solo se consultaron la skill, su checklist, plantilla y definición del eval.
- **Posición de prueba aportada:** revisión autorizada desde una red externa para E1–E2; ubicación/IP y familia IP no especificadas. E3 identifica un Registered User no Admin; no aporta ubicación ni identidad de cuenta.
- **Bloqueos:** SEC-001 (CRÍTICA), SEC-002 y SEC-003 (ALTAS).
- **Siguiente acción:** responsables de infraestructura y aplicación deben acordar y ejecutar la contención, rotación y correcciones descritas; después aportar nueva evidencia de cierre y completar los controles esenciales pendientes.

Este dictamen se limita a los hechos del escenario; no certifica un sistema real ni garantiza ausencia de vulnerabilidades. El certificado válido no compensa los tres bloqueos confirmados.

## 2. Cobertura y metodología

Se contrastó la evidencia con todos los IDs del checklist. TLS-01 se desglosa para no extender la validación del certificado a protocolos o renovación. Los totales cuentan filas de la matriz, no hallazgos.

| Total controles/subcontroles | VALIDADO | FALLA | NO VERIFICADO | NO APLICA |
|---:|---:|---:|---:|---:|
| 35 | 1 | 4 | 30 | 0 |

| Hallazgos CRÍTICOS | ALTOS | MEDIOS | BAJOS | INFORMATIVOS |
|---:|---:|---:|---:|---:|
| 1 | 2 | 0 | 0 | 0 |

Las carencias de evidencia no se cuentan como vulnerabilidades confirmadas. Ningún control se declara no aplicable por desconocimiento de la arquitectura.

### Registro de evidencia sanitizada

Todas las evidencias proceden del prompt del eval 2, referido a producción ficticia. No hay capturas originales, marcas temporales ni códigos de salida de herramientas; los métodos siguientes son los declarados por el escenario, no ejecutados en esta evaluación.

| ID | Método / activo aportado | Resultado disponible |
|---|---|---|
| E1 | Revisión autorizada externa; `GET /.env` | `200 text/plain`, nombres `APP_KEY` y `DB_PASSWORD`, valores presentes pero deliberadamente no facilitados. No se solicitan ni reproducen valores. |
| E2 | Misma red externa; 8090/TCP, `/health` y endpoints de cálculo | `/health` devuelve 200; endpoints de cálculo públicos sin autenticación. Rutas de cálculo, esquema de transporte y familias IP no aportados. |
| E3 | `GET /api/admin/ingestion/runs`, usuario Registered User no Admin | 200 y ledger. No se aporta ni reproduce su contenido. No demuestra permiso de escritura o ejecución de Ingestion Runs. |
| E4 | Comprobación HTTPS declarada | Certificado válido. Sin detalles de protocolos, renovación ni topología. |
| E5 | Información de continuidad | Política de backups desconocida y sin restauración probada; no demuestra que no existan copias. |
| E6 | Auditoría de dependencias intentada por el auditor del escenario | Falló por timeout de red. Sin resultados utilizables: no equivale a cero vulnerabilidades. |

## 3. Puertos y servicios

Mapa parcial: cliente externo → web Laravel/React → datos no inventariados; también existe acceso externo directo a 8090/TCP. CDN/proxy, contenedores, NAT y conexiones entre servicios son desconocidos.

| Activo / servicio | Puerto / protocolo / IPv4-IPv6 | Bind / publicación | Acceso esperado | Firewall host/proveedor | Prueba externa y origen | Estado / evidencia |
|---|---|---|---|---|---|---|
| Web `app.example.test` | HTTPS; puerto y familia IP no indicados | Desconocidos | Web pública por HTTPS | Desconocido | E4 no identifica posición de prueba | Certificado VALIDADO, E4; red NO VERIFICADA |
| Servicio de cálculo | 8090/TCP; familia IP desconocida | Dirección de escucha y mecanismo de publicación desconocidos | Interno, acceso restringido entre servicios | Reglas desconocidas; aislamiento ineficaz según E2 | Red externa autorizada no identificada; health 200 y cálculo público | FALLA, E2 / SEC-002 |
| HTTP, SSH/RDP y paneles | Puertos efectivos desconocidos | Desconocidos | HTTP con redirección si aplica; administración restringida | Desconocido | Ninguna aportada | NO VERIFICADO; inventario y pruebas autorizadas pendientes |
| DB, caché, métricas, dev servers y otros servicios | TCP/UDP, IPv4/IPv6 no inventariados | Desconocidos | Privados según función | Desconocido | Ninguna aportada | NO VERIFICADO; confirmar existencia y superficie |

E2 acredita alcance externo de 8090; no permite atribuirlo a una regla concreta, a `0.0.0.0`, ni extenderlo a IPv6. No se presume que los puertos convencionales de otros servicios estén escuchando.

## 4. Archivos y rutas protegidas

| Archivo / ruta | Protección esperada | Estado HTTP / tipo / respuesta sanitizada | ACL / document root | Estado / evidencia |
|---|---|---|---|---|
| `/.env` | Sin contenido privado por HTTP | 200, `text/plain`; `APP_KEY` y `DB_PASSWORD` con valores presentes, omitidos | Desconocidos | FALLA, E1 / SEC-001: fuga confirmada |
| `/.env.production`, `/.env.bak` | Variantes inaccesibles | Sin evidencia | Desconocidos | NO VERIFICADO |
| `/.git/HEAD`, `/.git/config` | Repositorio inaccesible | Sin evidencia | Desconocidos | NO VERIFICADO |
| `/storage/logs/laravel.log`, dumps, backups y claves | Contenido privado inaccesible | Sin evidencia; ubicaciones efectivas pendientes | Desconocidos | NO VERIFICADO |
| `/database/database.sqlite` y auxiliares `-wal`/`-shm`, si se usa SQLite | Datos fuera de acceso web | Sin evidencia; motor DB desconocido | Desconocidos | NO VERIFICADO |
| Ruta inexistente de control, por definir | Distinguir error/fallback de archivo real | Sin evidencia | No determinado | NO VERIFICADO |
| `/api/admin/ingestion/runs` | Solo Admin autorizado | 200 y ledger a Registered User no Admin; tipo no aportado | Control de autorización del servidor | FALLA, E3 / SEC-003 |

En E1 la fuga está confirmada por el contenido declarado, no por el 200 aislado. No hay indicios de shell SPA, login o WAF en esa evidencia. Falta una respuesta de control para futuras comprobaciones, pero esto no invalida el hecho aportado. No se descargaron secretos, bases, repositorios ni backups.

## 5. Matriz de validación

**E:** esencial; **cond.**: esencial si existe el componente o mecanismo indicado. Los controles condicionales siguen pendientes hasta determinar aplicabilidad.

| ID | Control / activo | E | Resultado esperado | Observado / evidencia | Estado | Hallazgo |
|---|---|---|---|---|---|---|
| NET-01 | Inventario de red | Sí | Listeners, interfaces, NAT y contenedores justificados | E2 solo identifica 8090; falta inventario completo | NO VERIFICADO | — |
| NET-02 | Aislamiento de servicios | Sí | Motor y otros servicios internos restringidos | 8090 accesible externamente, E2; reglas desconocidas | FALLA | SEC-002 |
| NET-03 | Acceso administrativo al host | Sí | Acceso restringido y robusto, con recuperación | Faltan configuración, cuentas y controles de acceso | NO VERIFICADO | — |
| NET-04 | Egreso y DNS | No | Destinos limitados según uso y DNS sin registros huérfanos | Sin reglas de salida, integraciones ni registros | NO VERIFICADO | — |
| FILE-01 | Document root y routing | Sí | Solo contenido público servido | E1 muestra fuga; causa, root, aliases y reglas sin inspeccionar | NO VERIFICADO | SEC-001 |
| FILE-02 | Archivos privados por HTTP | Sí | Ningún archivo privado accesible | `/.env` expuesto, E1; otras rutas pendientes | FALLA | SEC-001 |
| FILE-03 | Gestión y distribución de secretos | Sí | Sin secretos en artefactos públicos; separación y rotación | E1 acredita fuga HTTP; repositorio/build, privilegios y rotación desconocidos | NO VERIFICADO | SEC-001 |
| FILE-04 | Permisos y cuenta de servicio | Sí | Privilegios mínimos y escritura limitada | Sin ACL, propietarios ni usuario efectivo | NO VERIFICADO | — |
| FILE-05 | Uploads y source maps | No | Uploads controlados, no ejecutables; mapas revisados | Existencia/configuración no aportadas | NO VERIFICADO | — |
| TLS-01a | Certificado HTTPS | Sí | Certificado válido del objetivo | Validez declarada en E4 | VALIDADO | — |
| TLS-01b | Protocolos y renovación | Sí | TLS moderno y renovación operativa | E4 no cubre estos componentes | NO VERIFICADO | — |
| TLS-02 | Redirección y transporte interno | Sí | HTTPS antes de credenciales; origen protegido | Sin HTTP, topología ni evidencia del origen | NO VERIFICADO | — |
| TLS-03 | Trusted proxies y hosts | Sí | Confianza restringida y esquema/IP correctos | Configuración efectiva ausente | NO VERIFICADO | — |
| TLS-04 | HSTS y mixed content | No | Política coherente y recursos HTTPS | Sin cabeceras ni inspección del frontend | NO VERIFICADO | — |
| HTTP-01 | Cabeceras de seguridad | No | CSP/framing y otras políticas adecuadas | Sin respuestas/cabeceras aportadas | NO VERIFICADO | — |
| HTTP-02 | CORS | Sí | Orígenes limitados, credenciales controladas | Sin política ni respuestas de prueba | NO VERIFICADO | — |
| HTTP-03 | Caché privada | Sí | Sin mezcla de sesiones ni caché pública de datos privados | Sin reglas o cabeceras de caché | NO VERIFICADO | — |
| HTTP-04 | Métodos, límites y errores | No | Límites adecuados, errores sin información privada | Sin configuración ni evidencia de errores | NO VERIFICADO | — |
| AUTH-01 | Autorización de rol | Sí | Ledger reservado a Admin en servidor | Registered User obtiene ledger con 200, E3 | FALLA | SEC-003 |
| AUTH-02 | Propiedad de objetos | Sí | Lecturas/escrituras aisladas entre usuarios | Sin pruebas A/B con objetos sintéticos | NO VERIFICADO | — |
| AUTH-03 | Sesiones | Sí | Cookies seguras, renovación y revocación | Sin cookies ni pruebas de ciclo de sesión | NO VERIFICADO | — |
| AUTH-04 | CSRF | Cond. | Mutaciones con cookies protegidas | Mecanismo de autenticación y protección no aportados | NO VERIFICADO | — |
| AUTH-05 | Credenciales y rate limits | Sí | Hash, recuperación y límites robustos | Sin configuración ni pruebas específicas | NO VERIFICADO | — |
| APP-01 | Configuración efectiva | Sí | Producción, debug desactivado y build correcto | Entorno declarado; configuración efectiva desconocida | NO VERIFICADO | — |
| APP-02 | Entradas y recursos | Sí | Validación, consultas/salida seguras y límites | Sin revisión ni pruebas autorizadas | NO VERIFICADO | — |
| APP-03 | Frontera entre servicios | Sí | Cálculo interno restringido y autenticado según topología | Endpoints de cálculo públicos sin autenticación, E2 | FALLA | SEC-002 |
| APP-04 | Integraciones especiales | No | Webhooks/WebSocket protegidos si existen | Existencia no determinada | NO VERIFICADO | — |
| SUPPLY-01 | Soporte y dependencias | Sí | Versiones soportadas, auditoría completa y riesgos tratados | Timeout, E6; versiones sin inventariar | NO VERIFICADO | — |
| SUPPLY-02 | Build y CI | No | Artefactos reproducibles y entrega con mínimo privilegio | Sin lockfiles, pipeline ni procedencia | NO VERIFICADO | — |
| SUPPLY-03 | Contenedores | No | Sin privilegios/mounts peligrosos si existen | Uso y configuración desconocidos | NO VERIFICADO | — |
| OPS-01 | Datos y almacenamiento | Sí | Datos privados y credenciales de mínimo privilegio | E1 expone DB_PASSWORD; alcance DB y permisos desconocidos | NO VERIFICADO | SEC-001 |
| OPS-02 | Backups y recuperación | Sí | Copias protegidas, retención y restauración probada | Política desconocida; sin restauración probada, E5 | NO VERIFICADO | — |
| OPS-03 | Logs y detección | No | Registros protegidos, retención y alertas operativas | Sin evidencia de configuración o vigilancia | NO VERIFICADO | — |
| OPS-04 | Workers y scheduler | No | Supervisión, límites y privilegios mínimos | Existencia/configuración no aportadas | NO VERIFICADO | — |
| OPS-05 | Respuesta y rollback | No | Runbook, responsables y revalidación | Sin procedimiento aportado | NO VERIFICADO | — |

## 6. Hallazgos priorizados

### SEC-001 — Exposición externa de secretos en `/.env`

- **Severidad / prioridad / bloqueo:** CRÍTICA / P0 inmediato / sí. **Estado: abierto.**
- **Activo y controles:** `app.example.test/.env`; FILE-02. FILE-01, FILE-03 y OPS-01 requieren investigación complementaria.
- **Hecho:** E1 confirma contenido de configuración con valores de `APP_KEY` y `DB_PASSWORD`, no un mero estado HTTP exitoso.
- **Impacto:** confidencialidad de secretos comprometida; posible acceso a datos si la credencial sigue vigente y la DB es alcanzable, y afectación de material cifrado/firmado según el uso de APP_KEY. No se confirma acceso a la DB, explotación ni exfiltración adicional.
- **Acción:** bloquear inmediatamente la entrega de archivos privados en todos los puntos de servicio relevantes; corregir root/routing para servir solo artefactos públicos (Laravel `public/` donde corresponda). Preservar registros sanitizados, delimitar ventana de exposición y revisar accesos. Inventariar mediante el responsable los secretos potencialmente expuestos y rotarlos sin solicitarlos en el informe. Purgar respuestas sensibles de cachés si existen.
- **Responsables / plazo:** infraestructura y responsable Laravel, con custodio de datos; contención inmediata y rotación antes de publicar.
- **Cierre:** nueva comprobación externa autorizada de `/.env`, variantes y hosts identificados devuelve denegación o ausencia sin contenido sensible, comparada con control inexistente. Aportar constancia sanitizada de revocación de credenciales anteriores, actualización de consumidores y funcionamiento esperado.
- **Rollback / efecto operativo:** DB_PASSWORD requiere coordinar DB y consumidores. APP_KEY puede invalidar sesiones/cookies y acceso a datos cifrados: inventariar usos y preparar migración/recifrado y recuperación en entorno aislado. No conservar la clave filtrada como fallback activo indefinido ni reactivar secretos revocados; recuperar con credenciales nuevas y configuración segura. No reabrir `/.env` para restaurar servicio.

### SEC-002 — Servicio de cálculo público sin autenticación

- **Severidad / prioridad / bloqueo:** ALTA / P0 inmediato / sí. **Estado: abierto.**
- **Activo y controles:** 8090/TCP; NET-02 y APP-03.
- **Hecho:** E2 acredita acceso desde una red externa y cálculo sin autenticación; `/health` público por sí solo no sería evidencia suficiente de este impacto.
- **Impacto:** uso no autorizado de cálculo y posible agotamiento de recursos; acceso a datos o cambios dependerían de las capacidades no descritas. No se invocaron cálculos ni se demostró indisponibilidad.
- **Acción:** restringir enlace/publicación, firewall host/proveedor y rutas de proxy según la topología real. Permitir solo consumidores internos necesarios; añadir identidad de servicio y transporte privado/TLS cuando corresponda, además de límites de recursos.
- **Responsables / plazo:** infraestructura y responsable del motor/API; antes de publicar, con contención inmediata.
- **Cierre:** contrastar inventario, reglas efectivas y pruebas externas autorizadas en IPv4/IPv6 aplicables: sin acceso público a cálculo por 8090 ni por aliases/proxy. Validar comunicación legítima interna; pruebas funcionales de cálculo solo con fixtures aislados, sin trabajos de producción.
- **Rollback / efecto operativo:** conservar vía administrativa de recuperación y configuración previa para diagnóstico. Si se interrumpe el consumidor legítimo, ajustar únicamente su acceso privado o mantener el servicio cerrado; no restaurar la publicación sin autenticación.

### SEC-003 — Registered User puede leer un recurso Admin

- **Severidad / prioridad / bloqueo:** ALTA / P0 antes de publicar / sí. **Estado: abierto.**
- **Activo y control:** `GET /api/admin/ingestion/runs`; AUTH-01.
- **Hecho:** E3 entrega 200 y ledger a un usuario sin rol Admin.
- **Impacto:** lectura no autorizada de información operativa y ruptura de la frontera de rol. No se infiere permiso para crear, cancelar o ejecutar Ingestion Runs.
- **Acción:** aplicar autorización Admin en servidor mediante política/middleware apropiado, denegar por defecto y revisar rutas administrativas relacionadas. Evitar confianza en roles enviados por el cliente; revisar caché privada del endpoint.
- **Responsable / plazo:** backend Laravel; antes de publicar.
- **Cierre:** con cuentas y ledger sintéticos, Registered User recibe 403 sin datos; visitante recibe rechazo de autenticación/autorización sin ledger; Admin autorizado obtiene 200. Verificar cobertura de rutas relacionadas mediante pruebas aisladas, sin disparar Ingestion Runs.
- **Rollback / efecto operativo:** si la corrección bloquea al Admin, corregir el mapeo de permisos o deshabilitar temporalmente el endpoint; no volver a habilitar acceso a usuarios sin rol.

## 7. Plan de corrección y revalidación

Todas las acciones siguientes son propuestas, no ejecutadas. Ningún hallazgo está resuelto.

| Prioridad | Hallazgo / pendiente | Acción | Responsable propuesto | Verificación de cierre | Dependencias / rollback |
|---|---|---|---|---|---|
| P0 inmediato | SEC-001 | Contener fuga, investigar alcance y rotar secretos | Infraestructura, Laravel y datos | Denegación externa sin secretos + revocación acreditada + consumidores operativos | Coordinar cachés, cifrado y sesiones; recuperación sin reintroducir secretos filtrados |
| P0 inmediato | SEC-002 | Aislar cálculo y restringir frontera de servicio | Infraestructura y motor/API | Evidencia de red efectiva y acceso interno autorizado | Inventario de consumidores; rollback solo privado |
| P0 antes de publicar | SEC-003 | Aplicar autorización Admin en servidor | Backend Laravel | Matriz visitante/Registered User/Admin con datos sintéticos | Denegar acceso durante incidencias; sin activar Ingestion Runs |
| P1 antes de reconsiderar | SUPPLY-01, E6 | Repetir auditoría con conectividad disponible e inventariar versiones de producción | Responsable de dependencias/CI | Resultado completo fechado, versiones, advisories y tratamiento de riesgos | Timeout nuevo mantiene NO VERIFICADO; no ejecutar correcciones automáticas |
| P1 antes de reconsiderar | OPS-02, E5 | Definir política y probar restauración aislada | Operaciones y datos | Retención, acceso/cifrado de copias, resultado de restauración e integridad y tiempos acordados | Copia fuente preservada; sin sobrescribir producción |
| P1 antes de reconsiderar | Demás esenciales pendientes | Completar evidencias de la sección 8 | Infraestructura, backend y QA | Matriz actualizada con evidencia nueva y alcance explícito | Ventanas y datos de prueba acordados |

## 8. Pendientes, excepciones y riesgos residuales

Esta tabla agrupa los 30 controles/subcontroles no verificados de la matriz. Las próximas pruebas quedan propuestas para una revisión posterior autorizada.

| Controles pendientes | Motivo | Acceso o evidencia necesaria | Próxima prueba |
|---|---|---|---|
| NET-01, NET-03, NET-04 | Inventario y administración desconocidos | Topología, listeners, publicaciones, reglas host/proveedor, DNS y acceso administrativo | Conciliar IPv4/IPv6 y TCP/UDP; verificar restricciones desde posiciones identificadas y recuperación administrativa |
| FILE-01, FILE-03, FILE-04, FILE-05 | Root, secretos, ACL y uploads sin inspección | Configuración sanitizada, permisos, proceso de rotación, inventario de artefactos/uploads | Revisar root/aliases, mínimo privilegio, ausencia de secretos en builds y controles de uploads si existen |
| TLS-01b, TLS-02, TLS-03, TLS-04 | Solo consta certificado válido | Configuración TLS, renovación, proxy/CDN, hosts y HTTP | Verificar protocolos, renovación, redirección, transporte al origen, confianza proxy y contenido mixto/HSTS |
| HTTP-01, HTTP-02, HTTP-03, HTTP-04 | Sin políticas ni cabeceras | Respuestas sanitizadas, CORS, reglas de caché y límites | Comprobar cabeceras, orígenes autorizados/no autorizados, separación de caché, métodos y errores |
| AUTH-02, AUTH-03, AUTH-04, AUTH-05 | Sin evidencia del ciclo de identidad | Cuentas y objetos sintéticos, mecanismo de auth, cookies/configuración sanitizadas | Pruebas A/B de propiedad, renovación/logout, CSRF si cookies, recuperación y rate limits; sin fuerza bruta |
| APP-01, APP-02, APP-04 | Configuración y módulos desconocidos | Configuración efectiva sin secretos, versiones y puntos de entrada/integraciones | Confirmar producción/debug/build; revisar validación y límites; determinar y probar integraciones existentes |
| SUPPLY-01, SUPPLY-02, SUPPLY-03 | E6 inconcluyente; entrega y contenedores desconocidos | Auditoría completa, inventario, lockfiles y configuración CI/contenedores si existen | Mapear advisories a versiones y uso; comprobar procedencia y privilegios de entrega/runtime |
| OPS-01, OPS-02 | Alcance de datos y recuperación sin demostrar | Topología DB/storage, permisos y evidencia de copias/restauración | Verificar aislamiento y mínimo privilegio; restaurar copia en entorno aislado y comprobar integridad |
| OPS-03, OPS-04, OPS-05 | Operación y respuesta no documentadas en el escenario | Políticas de logs, supervisión y runbooks | Revisar retención/acceso y alertas, supervisión/límites si hay workers, responsables y ensayo de recuperación |

**Limitaciones y riesgos residuales:** las fechas, versiones, IPs, transporte del motor, arquitectura y alcance real de los secretos son desconocidos. No puede afirmarse explotación, ausencia de vulnerabilidades de dependencias, recuperabilidad ni aislamiento de datos. No consta ninguna excepción aceptada ni control compensatorio. No se ha verificado si la ruta de este informe se publica; se utiliza exclusivamente como artefacto offline en la ubicación indicada por el solicitante y no contiene valores secretos.

**Criterios para reconsiderar el dictamen:** cerrar SEC-001–SEC-003 con evidencia nueva conservando la anterior; acreditar contención y revocación de secretos, aislamiento del cálculo y autorización Admin; completar los controles esenciales aplicables, incluida auditoría de dependencias y recuperación probada. Resolver nuevos bloqueos que aparezcan. Si se cierran los fallos pero quedan esenciales sin comprobar, el dictamen será VALIDACIÓN INCOMPLETA; un dictamen favorable requiere esenciales validados y tratamiento explícito de los riesgos residuales no esenciales.
