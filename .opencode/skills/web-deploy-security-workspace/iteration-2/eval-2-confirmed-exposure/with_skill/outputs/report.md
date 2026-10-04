# Informe de validación de seguridad web

## 1. Dictamen ejecutivo

- **Dictamen: NO APTO.** Bloquean la publicación la fuga de secretos en `/.env` (SEC-001), el servicio de cálculo accesible externamente sin autenticación (SEC-002) y la lectura del ledger Admin por un Registered User no Admin (SEC-003).
- **Objetivo / entorno / stack:** `app.example.test`, ficticio; producción Laravel/React. Versiones, release, commit, proveedor y topología no aportados.
- **Fecha de elaboración:** 2026-10-04, según el entorno de sesión; hora y zona no disponibles. **Fecha de captura de E1–E6:** desconocida.
- **Modo:** evaluación offline del prompt del eval 2, iteración 2; evidencias aportadas sin reproducción. No se ejecutaron comandos, peticiones, pruebas ni cambios del sistema.
- **Accesos y posición:** sin acceso al despliegue. E1–E2 describen una revisión autorizada desde la misma red externa, sin identificar IP, ubicación o familia IP; E3 identifica el rol de la cuenta, no su ubicación.
- **Siguiente acción:** acordar contención inmediata y rotación de secretos, aislar el cálculo y corregir la autorización en servidor; obtener evidencia nueva de cierre y completar los esenciales pendientes antes de reconsiderar la publicación.

El certificado válido no compensa estos bloqueos. Este dictamen describe exclusivamente el escenario aportado, no una auditoría real ni una certificación de seguridad.

## 2. Cobertura y metodología

Se contrastaron E1–E6 con todos los IDs del checklist actualizado. FILE-01 se divide entre el resultado de protección y su configuración/causa; TLS-01, entre certificado y protocolos/renovación. Un resultado contradicho se marca FALLA aunque se desconozca su causa.

| Total controles/subcontroles | VALIDADO | FALLA | NO VERIFICADO | NO APLICA |
|---:|---:|---:|---:|---:|
| 36 | 1 | 5 | 30 | 0 |

| Hallazgos CRÍTICOS | ALTOS | MEDIOS | BAJOS | INFORMATIVOS |
|---:|---:|---:|---:|---:|
| 1 | 2 | 0 | 0 | 0 |

Recuento por familias: NET 4, FILE 6, TLS 5, HTTP 4, AUTH 5, APP 4, SUPPLY 3, OPS 5. Los hallazgos consolidan controles relacionados; desconocimiento no equivale a vulnerabilidad ni a NO APLICA.

### Evidencia aportada, sanitizada

Fuente única de hechos: prompt del eval 2. Todas las fechas de captura son desconocidas; no se aportan salidas originales ni códigos de salida. Los métodos descritos pertenecen al escenario, no a ejecuciones de esta evaluación.

| ID | Método / activo | Resultado aportado |
|---|---|---|
| E1 | Revisión autorizada externa, `GET /.env` | 200 `text/plain`; nombres `APP_KEY` y `DB_PASSWORD` con valores presentes, deliberadamente omitidos. |
| E2 | Misma red externa, 8090/TCP | `/health` responde 200; endpoints de cálculo públicos sin autenticación. Rutas de cálculo y software/proceso no identificados. |
| E3 | `GET /api/admin/ingestion/runs`, Registered User no Admin | 200 y ledger; contenido no proporcionado ni reproducido. |
| E4 | Comprobación HTTPS declarada | Certificado válido; sin detalle de protocolos, renovación o proxy/origen. |
| E5 | Información de continuidad | Política de backups desconocida y sin restauración probada. No acredita ausencia de copias. |
| E6 | Auditoría de dependencias del escenario | Timeout de red: sin resultado concluyente, no equivale a cero vulnerabilidades. |

Se consultaron la skill actualizada, checklist, plantilla y prompt. Incidencia de lectura: un intento de localizar el prompt por las posiciones antiguas mostró accidentalmente dos líneas de criterios del eval 1; después se localizó únicamente el id y prompt del eval 2 mediante búsqueda dirigida. No se consultaron las expectativas del eval 2 ni el informe anterior. Los hechos de este informe proceden exclusivamente de E1–E6.

## 3. Puertos y servicios

Superficie conocida: cliente externo → web Laravel/React; cliente externo → servicio de cálculo en 8090/TCP. CDN/proxy, enlaces entre aplicaciones, datos, contenedores y NAT no descritos. **El puerto 8090 no permite identificar FastAPI, un proceso concreto ni un servidor de desarrollo.**

| Activo / servicio | Puerto / protocolo / IPv4-IPv6 | Bind / publicación | Acceso esperado | Firewall host/proveedor | Prueba externa y origen | Estado / evidencia |
|---|---|---|---|---|---|---|
| Web `app.example.test` | HTTPS; puerto y familia no aportados | Desconocidos | Web pública por HTTPS | Desconocido | E4 no identifica posición | Certificado VALIDADO, E4; superficie restante NO VERIFICADA |
| Servicio de cálculo, implementación desconocida | 8090/TCP; familia desconocida; respuesta HTTP, TLS no descrito | Bind y mecanismo de publicación desconocidos | Cálculo restringido a consumidores autorizados | Reglas desconocidas; protección efectiva insuficiente según E2 | Red externa autorizada no identificada, health 200 y cálculo sin autenticación | FALLA, E2 / SEC-002 |
| Administración del host/paneles | Puertos/protocolos desconocidos | Desconocidos | Acceso administrativo restringido | Desconocido | Ninguna evidencia | NO VERIFICADO; inventario y comprobación autorizada pendientes |
| DB/storage, caché, métricas y otros servicios, si existen | Sin inventario TCP/UDP ni IPv4/IPv6 | Desconocidos | Privados según función | Desconocido | Ninguna evidencia | NO VERIFICADO; determinar existencia y alcance |

E2 confirma alcance externo, pero no identifica la regla, el bind o la publicación que lo permite. No se generaliza a IPv6, otros puertos ni a un eventual origen tras CDN.

## 4. Archivos y rutas protegidas

| Archivo / ruta | Protección esperada | Estado HTTP / tipo / respuesta sanitizada | ACL / document root | Estado / evidencia |
|---|---|---|---|---|
| `/.env` | Ningún contenido privado por HTTP | 200 `text/plain`; variables sensibles con valores presentes, omitidos | Configuración desconocida; protección efectiva fallida | FALLA, E1 / SEC-001 |
| `/.env.production`, `/.env.bak` | Variantes privadas inaccesibles | Sin evidencia | Desconocidos | NO VERIFICADO |
| `/.git/HEAD`, `/.git/config` | Repositorio inaccesible | Sin evidencia | Desconocidos | NO VERIFICADO |
| `/storage/logs/laravel.log` | Logs privados inaccesibles | Sin evidencia | Desconocidos | NO VERIFICADO |
| Backups, dumps, claves y archivos de DB | Inaccesibles por HTTP | Sin rutas reales ni motor DB aportados | Desconocidos | NO VERIFICADO; inventariar antes de probar |
| Ruta inexistente de control, pendiente de definir | Identificar errores/fallback | Sin respuesta aportada | No determinado | NO VERIFICADO |
| `/api/admin/ingestion/runs` | Ledger reservado a Admin | 200 y ledger para Registered User no Admin; tipo no aportado | Autorización en servidor insuficiente | FALLA, E3 / SEC-003 |

E1 demuestra fuga por su contenido declarado, no por el 200 aislado. No hay evidencia de fallback SPA, login o WAF en esa respuesta. La falta de comparación con ruta inexistente no anula una fuga ya confirmada por el escenario. Las futuras comprobaciones deberán usar comparación y evidencia mínima sanitizada, sin descargar secretos ni archivos masivos.

## 5. Matriz de validación

**E:** esencial cuando aplica. Los componentes cuya existencia o mecanismo se desconocen permanecen NO VERIFICADO hasta determinar aplicabilidad. Método de evaluación: contraste con E1–E6; «sin evidencia» significa que no se ejecutó ninguna prueba adicional.

| ID | Control / activo | E | Resultado esperado | Observado / evidencia | Estado | Hallazgo |
|---|---|---|---|---|---|---|
| NET-01 | Inventario | Sí | Listeners, interfaces, NAT y publicaciones justificados | E2 solo identifica 8090; inventario incompleto | NO VERIFICADO | — |
| NET-02 | Aislamiento de servicios | Sí | Acceso interno restringido | Cálculo accesible externamente sin autenticación, E2 | FALLA | SEC-002 |
| NET-03 | Administración del host | Sí | Restricción, identidad robusta y recuperación | Sin configuración de acceso administrativo | NO VERIFICADO | — |
| NET-04 | Egreso/DNS | No | Egreso controlado y registros coherentes | Sin integraciones, reglas ni DNS | NO VERIFICADO | — |
| FILE-01a | Resultado del servicio de archivos | Sí | Solo artefactos públicos servidos | Se entrega configuración privada, E1 | FALLA | SEC-001 |
| FILE-01b | Root, routing y causa | Sí | Root/aliases correctos, sin fuentes/listados privados | Configuración y causa exacta no aportadas | NO VERIFICADO | SEC-001 |
| FILE-02 | Archivos protegidos | Sí | Archivos privados inaccesibles por HTTP | `/.env` expuesto, E1; demás rutas pendientes | FALLA | SEC-001 |
| FILE-03 | Ciclo de secretos y artefactos | Sí | Sin secretos en build/repositorio/logs públicos; rotación | Distribución, unicidad y proceso de rotación desconocidos | NO VERIFICADO | SEC-001 |
| FILE-04 | ACL y usuarios de servicio | Sí | Privilegio mínimo, escritura limitada | Sin propietarios, ACL o identidad efectiva | NO VERIFICADO | — |
| FILE-05 | Uploads/source maps | No | Uploads controlados, no ejecutables; mapas revisados | Existencia y configuración desconocidas | NO VERIFICADO | — |
| TLS-01a | Certificado | Sí | HTTPS con certificado válido | Validez declarada, E4 | VALIDADO | — |
| TLS-01b | Protocolos/renovación | Sí | TLS moderno y renovación operativa | E4 no cubre estos aspectos | NO VERIFICADO | — |
| TLS-02 | HTTP y transporte al origen | Sí | Redirección y confidencialidad según topología | Sin HTTP, origen ni topología | NO VERIFICADO | — |
| TLS-03 | Proxies y hosts confiables | Sí | Confianza restringida, esquema/IP correctos | Sin configuración efectiva | NO VERIFICADO | — |
| TLS-04 | HSTS/mixed content | No | Política coherente y recursos HTTPS | Sin cabeceras ni recursos inspeccionados | NO VERIFICADO | — |
| HTTP-01 | Cabeceras | No | CSP, framing y políticas adecuadas | Sin respuestas/cabeceras | NO VERIFICADO | — |
| HTTP-02 | CORS | Sí | Solo orígenes necesarios | Sin política ni pruebas | NO VERIFICADO | — |
| HTTP-03 | Caché | Sí | Sin respuestas privadas públicas ni mezcla de sesiones | Sin reglas/cabeceras | NO VERIFICADO | — |
| HTTP-04 | Métodos, límites y errores | No | Límites adecuados, errores sin fugas | Sin configuración/evidencia | NO VERIFICADO | — |
| AUTH-01 | Roles en servidor | Sí | Lectura del ledger solo por Admin | Registered User no Admin recibe ledger, E3 | FALLA | SEC-003 |
| AUTH-02 | Propiedad de objetos | Sí | Aislamiento de usuarios | Sin pruebas A/B con datos sintéticos | NO VERIFICADO | — |
| AUTH-03 | Sesiones | Sí | Cookies y ciclo de sesión seguros | Sin cookies, renovación, logout o expiración | NO VERIFICADO | — |
| AUTH-04 | CSRF | Sí, si cookies | Mutaciones protegidas | Mecanismo de auth y protección desconocidos | NO VERIFICADO | — |
| AUTH-05 | Credenciales y límites | Sí | Hash, recuperación, rate limits y acceso reforzado | Sin configuración/pruebas | NO VERIFICADO | — |
| APP-01 | Producción efectiva | Sí | Debug desactivado y build adecuado | Entorno declarado no acredita configuración efectiva | NO VERIFICADO | — |
| APP-02 | Entradas y recursos | Sí | Validación, salida/consultas seguras y límites | Sin revisión ni pruebas | NO VERIFICADO | — |
| APP-03 | Frontera del servicio de cálculo | Sí | Acceso de servicio restringido/autenticado según topología | Cálculo público sin autenticación, E2 | FALLA | SEC-002 |
| APP-04 | Integraciones especiales | No | Webhooks/WebSocket protegidos si existen | Existencia y controles desconocidos | NO VERIFICADO | — |
| SUPPLY-01 | Versiones y dependencias | Sí | Soporte, parches y auditoría completa | Timeout E6; versiones no aportadas | NO VERIFICADO | — |
| SUPPLY-02 | Build/CI | No | Reproducibilidad, procedencia y mínimo privilegio | Sin lockfiles, pipeline ni artefactos | NO VERIFICADO | — |
| SUPPLY-03 | Contenedores | No | Privilegios/mounts mínimos si existen | Uso y configuración desconocidos | NO VERIFICADO | — |
| OPS-01 | DB/storage | Sí | Privacidad, separación y mínimo privilegio | E1 expone DB_PASSWORD; no acredita acceso DB ni permisos | NO VERIFICADO | SEC-001 |
| OPS-02 | Backups/recuperación | Sí | Copias protegidas y restauración demostrada | Política desconocida y sin prueba, E5 | NO VERIFICADO | — |
| OPS-03 | Logs/detección | No | Registros protegidos y vigilancia operativa | Sin política/configuración | NO VERIFICADO | — |
| OPS-04 | Workers/scheduler | No | Supervisión, límites y privilegios mínimos | Existencia/configuración desconocidas | NO VERIFICADO | — |
| OPS-05 | Respuesta/rollback | No | Runbook y responsables con revalidación | Sin procedimientos aportados | NO VERIFICADO | — |

## 6. Hallazgos priorizados

### SEC-001 — Exposición externa de secretos

- **Severidad / prioridad / bloqueo:** CRÍTICA / P0 inmediata / sí. **Estado: abierto.**
- **Activo y controles:** `/.env`; FILE-01a y FILE-02 fallan. FILE-01b, FILE-03 y OPS-01 requieren investigación.
- **Hecho:** E1 confirma entrega de `APP_KEY` y `DB_PASSWORD` con valores presentes. No se solicitan los valores ni se prueba su funcionamiento.
- **Impacto:** pérdida confirmada de confidencialidad de secretos. Posible acceso DB si la credencial está vigente y existe conectividad; posible afectación de datos cifrados o firmados según el uso real de APP_KEY. No se confirma explotación ni compromiso de toda la base.
- **Acción:** contener la entrega del archivo; corregir root/routing/aliases para servir únicamente artefactos públicos, usando `public/` en la parte Laravel. Revisar variantes y puntos de servicio autorizados; preservar registros sanitizados y determinar ventana de exposición. Inventariar los secretos potencialmente expuestos y rotarlos coordinadamente; revisar/purgar cachés sensibles si existen.
- **Responsables y plazo:** infraestructura, responsable Laravel y custodio de datos; contención inmediata, rotación y cierre antes de publicar.
- **Prueba de cierre:** revisión externa limitada de rutas conocidas y control inexistente acredita ausencia de contenido privado; evidencia sanitizada de revocación y actualización de consumidores, sin ensayar las credenciales filtradas. Confirmar funcionamiento con credenciales nuevas y revisar registros por accesos anómalos.
- **Rollback / efecto operativo:** coordinar cambio DB con consumidores. Antes de rotar APP_KEY, inventariar cifrado y sesiones, planificar recifrado/migración y recuperación aislada para evitar pérdida de datos. No mantener la clave comprometida como fallback activo ni reactivar credenciales revocadas. Recuperar con configuración segura y secretos nuevos; nunca reabrir `/.env` como rollback.

### SEC-002 — Servicio de cálculo accesible sin autenticación

- **Severidad / prioridad / bloqueo:** ALTA / P0 inmediata / sí. **Estado: abierto.**
- **Activo y controles:** 8090/TCP y endpoints de cálculo; NET-02, APP-03. Implementación desconocida.
- **Hecho:** E2 confirma alcance desde fuera y cálculo público sin autenticación. Un healthcheck público por sí solo no justificaría este hallazgo.
- **Impacto:** uso no autorizado y posible consumo abusivo de recursos; no se afirma ejecución arbitraria, modificación de datos ni indisponibilidad demostrada.
- **Acción:** inventariar consumidores legítimos y restringir enlace/publicación, firewall host/proveedor y proxy; acceso solo por rutas internas autorizadas. Establecer identidad de servicio, transporte privado/TLS y límites según la topología efectiva.
- **Responsables y plazo:** infraestructura y responsable del servicio de cálculo; contener de inmediato y cerrar antes de publicar.
- **Prueba de cierre:** reglas efectivas e inventario conciliados con pruebas externas autorizadas muestran que el cálculo no es público por 8090 ni aliases/proxy; verificar IPv4/IPv6 aplicables y acceso legítimo interno. Las pruebas funcionales usarán fixtures en entorno aislado, sin trabajos de producción.
- **Rollback / efecto operativo:** conservar acceso administrativo de recuperación. Si se interrumpe un consumidor, ajustar solo su acceso privado o mantener el servicio cerrado; no restaurar acceso público sin autenticación.

### SEC-003 — Lectura del ledger Admin por un usuario sin rol

- **Severidad / prioridad / bloqueo:** ALTA / P0 antes de publicar / sí. **Estado: abierto.**
- **Activo y control:** `GET /api/admin/ingestion/runs`, AUTH-01.
- **Hecho:** E3 confirma 200 y ledger para Registered User no Admin.
- **Impacto:** divulgación no autorizada de información operativa y ruptura de la frontera de rol. No prueba autorización para crear, cancelar o ejecutar Ingestion Runs.
- **Acción:** aplicar política/middleware Admin en servidor con denegación por defecto; no confiar en roles enviados por cliente. Revisar cobertura de rutas administrativas relacionadas y tratamiento de caché privada.
- **Responsable y plazo:** backend Laravel; antes de publicar.
- **Prueba de cierre:** con cuentas y ledger sintéticos, Registered User obtiene 403 sin ledger, visitante recibe denegación de autenticación/autorización sin datos y Admin autorizado obtiene 200. Cubrir rutas relacionadas sin activar Ingestion Runs; comprobar primero el aislamiento de la base de tests.
- **Rollback / efecto operativo:** ante bloqueo accidental de Admin, corregir permisos o deshabilitar temporalmente el endpoint; no restaurar acceso de usuarios sin rol.

## 7. Plan de corrección y revalidación

Las medidas son propuestas para un cambio posterior autorizado; ninguna se ejecutó ni cierra por sí sola un hallazgo.

| Prioridad | Hallazgo / pendiente | Acción | Responsable propuesto | Verificación de cierre | Dependencias / rollback |
|---|---|---|---|---|---|
| P0 inmediata | SEC-001 | Contener, investigar y rotar | Infraestructura/Laravel/datos | Ausencia de entrega privada; revocación y operación con secretos nuevos | Coordinar cifrado, sesiones, DB y caché; recuperación sin secretos filtrados |
| P0 inmediata | SEC-002 | Aislar cálculo y controlar acceso de servicio | Infraestructura/servicio de cálculo | Prueba externa negativa más acceso interno legítimo y reglas conciliadas | Inventariar consumidores; rollback solo privado |
| P0 antes de publicar | SEC-003 | Autorizar Admin en servidor | Backend Laravel | Matriz visitante/Registered User/Admin, sin datos fuera de rol | Fixtures aislados; deshabilitar ruta ante incidente |
| P1 antes de reconsiderar | SUPPLY-01 | Repetir auditoría con conectividad disponible e inventario de versiones | Responsable CI/dependencias | Informe completo, advisories aplicables y tratamiento de riesgos | Timeout repetido mantiene NO VERIFICADO; no hacer correcciones automáticas |
| P1 antes de reconsiderar | OPS-02 | Definir retención/protección y probar restauración | Operaciones/datos | Integridad, recuperación funcional y tiempos frente a objetivos acordados | Entorno aislado, fuente preservada, sin sobrescribir producción |
| P1 antes de reconsiderar | Resto de esenciales | Completar evidencia de sección 8 | Infraestructura/backend/QA | Matriz actualizada y nuevos bloqueos resueltos | Alcance y ventanas autorizados |

## 8. Pendientes, excepciones y riesgos residuales

La tabla agrupa los 30 subcontroles NO VERIFICADO; adicionalmente deben revalidarse todos los que FALLAN y completarse las variantes de FILE-02 y alcance de NET-02/APP-03 no cubiertos por E1–E2.

| Controles pendientes | Motivo | Acceso o evidencia necesaria | Próxima prueba propuesta |
|---|---|---|---|
| NET-01, NET-03, NET-04 | Superficie, administración y egreso desconocidos | Inventario, NAT/proxy, reglas host/proveedor, DNS y mecanismo administrativo | Conciliar TCP/UDP e IPv4/IPv6; verificar alcance desde posiciones identificadas y recuperación administrativa |
| FILE-01b, FILE-03, FILE-04, FILE-05 | Causa de fuga y protección local/artefactos desconocidas | Root/aliases, ACL, cuentas, proceso de secretos e inventario uploads/build | Inspección sanitizada de routing, mínimo privilegio y artefactos; probar uploads si existen |
| TLS-01b, TLS-02, TLS-03, TLS-04 | Solo consta validez del certificado | Configuración TLS/renovación, HTTP, topología/proxy y respuestas | Validar protocolos, renovación, redirección, origen, confianza de cabeceras y mixed content/HSTS |
| HTTP-01, HTTP-02, HTTP-03, HTTP-04 | Sin políticas ni respuestas | Cabeceras, reglas CORS/caché y límites | Probar orígenes autorizados/no autorizados, aislamiento de caché y errores sin fugas |
| AUTH-02, AUTH-03, AUTH-04, AUTH-05 | Sin evidencia de propiedad, sesiones o credenciales | Cuentas A/B y objetos sintéticos; configuración y cookies sanitizadas | Verificar propiedad, ciclo de sesión, CSRF según mecanismo, recuperación y límites sin fuerza bruta |
| APP-01, APP-02, APP-04 | Configuración e integraciones no descritas | Configuración efectiva sin secretos, entradas e inventario de módulos | Verificar debug/build, validar entradas/límites y determinar controles de integraciones existentes |
| SUPPLY-01, SUPPLY-02, SUPPLY-03 | Auditoría fallida y entrega desconocida | Resultado completo, versiones/lockfiles, pipeline y contenedores si existen | Mapear advisories a uso real; revisar procedencia y privilegios de CI/runtime |
| OPS-01, OPS-02 | Acceso a datos y recuperación no acreditados | Topología DB/storage, privilegios, política de copias y registros de restauración | Verificar aislamiento/mínimo privilegio y restaurar copia aislada con evidencia de integridad |
| OPS-03, OPS-04, OPS-05 | Operación y respuesta sin evidencia | Políticas de logs, supervisión y runbooks | Revisar retención, detección, límites/supervisión si hay workers y procedimiento de recuperación |

**Límites y riesgos residuales:** no se conocen fechas de captura, versiones, rutas de cálculo, privilegios DB, alcance completo de los secretos, topología ni política de continuidad. No se afirma explotación ni ausencia de vulnerabilidades. No constan excepciones aceptadas ni controles compensatorios. El informe se guarda en la ruta indicada para el eval offline; no se ha inspeccionado su eventual publicación por un servidor.

**Criterios para reconsiderar:** cerrar SEC-001–SEC-003 con evidencia nueva conservando el historial; acreditar contención y revocación, aislamiento/autenticación del cálculo y autorización Admin; completar todos los controles esenciales aplicables, incluida auditoría de dependencias y restauración. Resolver cualquier nuevo bloqueo. Si desaparecen los fallos pero quedan esenciales sin verificar, corresponde VALIDACIÓN INCOMPLETA. Un dictamen favorable requiere esenciales validados y tratamiento explícito de riesgos residuales no esenciales.
