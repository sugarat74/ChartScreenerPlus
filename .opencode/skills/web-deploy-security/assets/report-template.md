# Informe de validación de seguridad web

## 1. Dictamen ejecutivo

- **Dictamen:** [NO APTO / VALIDACIÓN INCOMPLETA / APTO CON CONDICIONES / APTO EN EL ALCANCE REVISADO]
- **Proyecto / entorno / release:** [...]
- **Fecha y hora con zona:** [...]
- **Objetivo(s) y alcance:** [hosts, rutas, infraestructura y exclusiones]
- **Modo:** [estático / en vivo / mixto / evidencias aportadas sin reproducción]
- **Accesos y posición de prueba:** [repositorio, host, exterior, cuentas sintéticas; sin credenciales]
- **Bloqueos:** [IDs y motivo, o ninguno confirmado]
- **Siguiente acción:** [...]

Este dictamen describe el alcance observado en la fecha indicada; no es una
certificación ni garantiza ausencia de vulnerabilidades.

## 2. Cobertura y metodología

| Total controles evaluados | VALIDADO | FALLA | NO VERIFICADO | NO APLICA |
|---:|---:|---:|---:|---:|
| [...] | [...] | [...] | [...] | [...] |

| Hallazgos CRÍTICOS | ALTOS | MEDIOS | BAJOS | INFORMATIVOS |
|---:|---:|---:|---:|---:|
| [...] | [...] | [...] | [...] | [...] |

Listar evidencias E-001, E-002, etc., con fecha, fuente, entorno, método/comando,
exit code/resultado y extracto sanitizado. Separar pruebas ejecutadas, evidencia
aportada, inspección estática y comandos propuestos. No contar recomendaciones como
pruebas ni controles ausentes como validados.

## 3. Puertos y servicios

| Activo / servicio | Puerto / protocolo / IPv4-IPv6 | Bind / publicación | Acceso esperado | Firewall host/proveedor | Prueba externa y origen | Estado / evidencia |
|---|---|---|---|---|---|---|
| [...] | [...] | [...] | [...] | [...] | [...] | [...] |

Explicar límites de red/CDN/NAT y puertos no comprobados. Si falta acceso, incluir
filas NO VERIFICADO con la comprobación pendiente, no una tabla vacía.

## 4. Archivos y rutas protegidas

| Archivo / ruta | Protección esperada | Estado HTTP / tipo / respuesta sanitizada | ACL / document root | Estado / evidencia |
|---|---|---|---|---|
| [...] | [...] | [...] | [...] | [...] |

Incluir variantes pertinentes, ruta inexistente de control y distinción entre fuga,
fallback SPA, login, WAF y respuesta inconcluyente. No reproducir secretos.

## 5. Matriz de validación

| ID | Control / activo | Esencial | Resultado esperado | Observado / evidencia | Estado | Hallazgo |
|---|---|---|---|---|---|---|
| [...] | [...] | [...] | [...] | [...] | [...] | [...] |

Representar todas las familias NET, FILE, TLS, HTTP, AUTH, APP, SUPPLY y OPS del
checklist. NO APLICA requiere motivo; NO VERIFICADO requiere qué falta.

## 6. Hallazgos priorizados

### SEC-001 — [Título]

- **Severidad / prioridad / bloqueo:** [...]
- **Activo y controles afectados:** [...]
- **Hecho observado y evidencia:** [E-xxx, sin credenciales]
- **Impacto y condiciones necesarias:** [...]
- **Acción correctiva concreta:** [...]
- **Responsable propuesto / plazo recomendado:** [...]
- **Prueba de cierre:** [acción, resultado esperado y ámbito]
- **Rollback / efecto operativo:** [cuando corresponda]
- **Estado:** [abierto / corregido pendiente de revalidación / cerrado con E-xxx nueva]

Repetir por hallazgo. Consolidar causas compartidas para evitar inflar los conteos.

## 7. Plan de corrección y revalidación

| Prioridad | Hallazgo | Acción | Responsable propuesto | Verificación de cierre | Dependencias / rollback |
|---|---|---|---|---|---|
| [...] | [...] | [...] | [...] | [...] | [...] |

## 8. Pendientes, excepciones y riesgos residuales

| Control no verificado / exclusión | Motivo | Acceso o evidencia necesaria | Próxima prueba |
|---|---|---|---|
| [...] | [...] | [...] | [...] |

Registrar excepciones justificadas, quién las aceptó si consta, vigencia y controles
compensatorios; no inventar aceptación. Terminar con los criterios exactos para
reconsiderar el dictamen. Si es revisión estática, indicar qué falta en el despliegue.
