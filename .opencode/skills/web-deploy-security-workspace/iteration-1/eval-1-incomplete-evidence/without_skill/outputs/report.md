# Informe de seguridad del despliegue — simulación offline

## Dictamen

**VALIDACIÓN INCOMPLETA: no hay evidencia suficiente para recomendar la publicación en producción.**

Los datos aportados no demuestran una fuga de `.env`, exposición pública del puerto 8090 ni una vulnerabilidad en las cookies descritas. Tampoco permiten certificar la seguridad del despliegue. La decisión de publicación queda pendiente de comprobar los controles efectivos y el entorno de producción.

Este informe es una evaluación de un escenario, **no una auditoría real**. Se limita a las evidencias E1–E5 de staging Laravel/React/FastAPI. No se han realizado solicitudes, ejecutado comandos, consultado otros archivos del proyecto ni aplicado cambios al sistema.

## Evaluación de las evidencias

| Evidencia | Observación aportada | Conclusión admisible | Límite de la conclusión |
| --- | --- | --- | --- |
| E1 | `GET /.env` devuelve `200 text/html`, con hash y longitud idénticos a una ruta inexistente de control y al shell SPA. No contiene variables ni secretos. | La respuesta corresponde al fallback SPA observado; el código 200 no demuestra acceso al archivo `.env`. No hay fuga acreditada en esta respuesta. | No demuestra que todos los archivos sensibles estén protegidos ni que producción se comporte igual. |
| E2 | El firewall del host permite TCP 443 desde Internet. | Existe una regla de entrada que permite ese tráfico en el host. | No confirma conectividad externa, servicio HTTPS operativo, certificado válido ni seguridad TLS. Faltan security group y reglas IPv6. |
| E3 | Hay un listener en `0.0.0.0:8090`. No hay prueba externa ni datos de NAT/proxy. | El servicio escucha en todas las interfaces IPv4 del entorno donde se observó. Su accesibilidad depende de la topología y los controles de red. | No acredita exposición a Internet, escucha IPv6, identidad del servicio ni acceso a endpoints sin autenticación. |
| E4 | `laravel_session`: Secure, HttpOnly, SameSite=Lax. `XSRF-TOKEN`: Secure, SameSite=Lax, sin HttpOnly. | Los atributos de la cookie de sesión son favorables. Que `XSRF-TOKEN` sea legible por JavaScript es coherente con el flujo CSRF de Laravel y no constituye por sí solo un fallo. | No verifica el rechazo de peticiones sin token CSRF válido, la configuración de dominios, la caducidad o la invalidación de sesiones. |
| E5 | `.env.example` declara `APP_DEBUG=false`. No se dispone de configuración efectiva ni acceso a producción. | El ejemplo documenta la intención de desactivar debug. | No prueba el valor efectivo en staging o producción, incluida la configuración cacheada. |

## Puertos y superficie de red

| Puerto / escucha | Estado observado | Exposición externa | Pendiente |
| --- | --- | --- | --- |
| TCP 443 | Permitido por el firewall del host desde Internet. | No verificada de extremo a extremo. | Revisar reglas perimetrales, IPv4/IPv6 y terminación HTTPS del destino de publicación. |
| TCP 8090 / `0.0.0.0` | Listener IPv4 presente. | Desconocida; no debe clasificarse como pública únicamente por el bind. | Identificar servicio y topología; revisar firewall, security group, NAT y rutas de proxy; verificar acceso desde los orígenes permitidos y no permitidos. |
| Otros puertos | Sin evidencia. | Desconocida. | Obtener el inventario de listeners y servicios publicados del entorno objetivo. |

## Archivos y configuración

| Recurso | Resultado disponible | Estado |
| --- | --- | --- |
| `/.env` | Respuesta HTML igual al shell SPA y al control inexistente, sin secretos. | Sin fuga demostrada en E1; no es evidencia de lectura del archivo. |
| `.env.example` | Contiene `APP_DEBUG=false`. | Valor documental; configuración efectiva no verificada. |
| Otros archivos sensibles o artefactos de despliegue | Sin datos. | Protección no evaluada. |

## Matriz de decisión

La prioridad indica el orden de cierre de las incertidumbres, no la severidad de una vulnerabilidad confirmada.

| Área | Estado | Prioridad previa a publicar | Criterio de cierre |
| --- | --- | --- | --- |
| Correspondencia staging/producción | Sin evidencia de producción. | Alta | Identificar el despliegue objetivo y aportar evidencia de sus controles efectivos; documentar diferencias respecto de staging. |
| Aislamiento de 8090 | Indeterminado. | Alta | Demostrar que solo los consumidores previstos pueden alcanzar el servicio, también a través de posibles proxies. |
| Perímetro y HTTPS | Regla de host conocida; resto incompleto. | Alta | Documentar reglas efectivas IPv4/IPv6 y verificar conectividad, certificado y configuración HTTPS. Si IPv6 no aplica, justificarlo. |
| Debug y errores | Solo valor de ejemplo. | Alta | Confirmar `APP_DEBUG=false` en la configuración efectiva y respuestas de error sin trazas ni datos sensibles en el entorno objetivo. |
| Archivos sensibles | E1 sin fuga; cobertura limitada. | Alta | Corroborar la raíz pública y las reglas de publicación, y verificar que los recursos sensibles no entregan contenido privado. |
| Sesión y CSRF | Atributos favorables; funcionamiento no evaluado. | Media | Verificar configuración de cookies y que operaciones con efectos rechazan tokens CSRF ausentes o inválidos; comprobar invalidación de sesión. |
| Otros controles de aplicación y operación | Sin evidencia sobre autorización, dependencias o recuperación. | Por determinar | Aportar resultados de pruebas de autorización, revisión de dependencias y restauración, con alcance y limitaciones documentados. |

## Próximos pasos verificables

Estas acciones quedan **propuestas para una revisión posterior autorizada**; no se han ejecutado en esta simulación.

1. **Delimitar producción.** Identificar versión desplegada, arquitectura, dominios y servicios previstos. Recoger evidencias sanitizadas del entorno objetivo; no solicitar ni adjuntar credenciales o valores secretos.
2. **Cerrar la incertidumbre de red.** Revisar conjuntamente firewall del host, security group, IPv6, NAT y proxy. Verificar posteriormente desde fuera y desde la red interna qué servicios son accesibles. Si 8090 es interno, acreditar que no puede alcanzarse desde orígenes no autorizados, ni directamente ni mediante una ruta pública del proxy.
3. **Comprobar HTTPS y archivos publicados.** Validar el servicio real en 443 y su TLS. Para rutas sensibles, conservar estado, tipo de contenido y comparación con un control inexistente y el shell SPA. Juzgar el contenido y las reglas efectivas, no solo el código HTTP.
4. **Confirmar configuración efectiva.** Aportar el valor efectivo de debug y la gestión de errores sin exponer secretos. La declaración de `.env.example` no satisface este cierre.
5. **Validar sesión y CSRF.** Probar operaciones con token válido, ausente e inválido y el cierre de sesión. Mantener la distinción entre la cookie de sesión HttpOnly y el token CSRF legible por el cliente.
6. **Completar la cobertura necesaria para publicar.** Incorporar evidencia de autorización de endpoints privados, estado de dependencias y recuperación mediante restauración. Su ausencia actual es una limitación de cobertura, no prueba de que esos controles fallen.

## Respuesta a «¿puedo publicar?»

**Con esta evidencia, no puedo dar el visto bueno de seguridad para producción.** El resultado es validación incompleta, no una declaración de vulnerabilidades confirmadas. Para reconsiderar la publicación hay que cerrar las incertidumbres prioritarias y aportar evidencia del entorno que realmente se publicará. E1, E4 y E5 no justifican, respectivamente, declarar una fuga de `.env`, exigir HttpOnly para `XSRF-TOKEN` o dar por desactivado debug en producción.
