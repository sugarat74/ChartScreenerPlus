# Evaluación de seguridad del despliegue

## Dictamen: VALIDACIÓN INCOMPLETA

**Con la evidencia disponible no se puede dar el visto bueno para publicar en producción.** Esto se debe a verificaciones pendientes, no a vulnerabilidades confirmadas por el escenario.

Esta es una simulación offline sobre un entorno **staging Laravel/React/FastAPI**, basada exclusivamente en E1–E5. No se han ejecutado pruebas reales, comandos ni solicitudes de red, ni se han consultado referencias o archivos adicionales del proyecto. Las comprobaciones indicadas más abajo son propuestas pendientes, no resultados obtenidos.

## Análisis de la evidencia

| Evidencia | Qué permite concluir | Qué no permite concluir |
| --- | --- | --- |
| **E1 — `GET /.env`**: `200 text/html`, hash y longitud idénticos al shell SPA y a `/ruta-inexistente-control`, sin variables ni secretos. | La respuesta observada corresponde al fallback SPA. **No hay fuga de `.env` demostrada**: el 200 por sí solo no prueba que se haya servido el archivo. | No acredita la protección de todos los archivos sensibles ni el comportamiento de producción. |
| **E2 — TCP 443**: permitido desde Internet en el firewall del host; faltan security group y reglas IPv6. | Hay una regla de host que permite ese tráfico. | No demuestra accesibilidad externa de extremo a extremo, un servicio HTTPS activo, certificado válido o configuración TLS adecuada. Tampoco demuestra que los demás puertos estén bloqueados. |
| **E3 — `0.0.0.0:8090`**: listener presente, sin prueba externa ni datos de NAT/proxy. | El proceso escucha en todas las interfaces IPv4 del entorno donde se observó. Es necesario conocer su función y controles de acceso. | **No prueba exposición pública de 8090**, escucha IPv6, identidad del proceso ni ausencia de autenticación. La accesibilidad depende de la red y del despliegue. |
| **E4 — Cookies**: `laravel_session` con Secure, HttpOnly y SameSite=Lax; `XSRF-TOKEN` con Secure y SameSite=Lax, sin HttpOnly. | Los atributos de la cookie de sesión son favorables. Que `XSRF-TOKEN` sea legible por JavaScript es coherente con el mecanismo CSRF de Laravel; no es un defecto por sí mismo. | Los atributos no prueban que el servidor valide CSRF, que las sesiones se invaliden correctamente ni que los dominios y rutas de cookies sean adecuados. SameSite no sustituye la validación CSRF. |
| **E5 — Debug**: `.env.example` contiene `APP_DEBUG=false`, sin configuración efectiva ni acceso a producción. | Se documenta una intención de configuración. | **No acredita que debug esté desactivado en ejecución**, ni en staging ni en producción. El ejemplo no determina la configuración efectiva o cacheada. |

## Estado de puertos y archivos

### Puertos

| Puerto | Evidencia local | Estado de exposición externa |
| --- | --- | --- |
| 443/TCP | Permiso de entrada en el firewall del host. | No verificado; faltan controles perimetrales y comprobación del servicio. |
| 8090/TCP | Listener IPv4 en `0.0.0.0`. | Desconocido; faltan topología, reglas y comprobación externa. |
| Otros | No aportada. | No evaluados. |

### Archivos y configuración

| Recurso | Resultado disponible | Valor probatorio |
| --- | --- | --- |
| `/.env` | HTML igual al shell SPA y a la ruta inexistente de control. | Sin contenido sensible expuesto en la respuesta descrita. No constituye una lectura acreditada de `.env`. |
| `.env.example` | Declaración `APP_DEBUG=false`. | Evidencia documental, no del valor efectivo. No hay datos sobre su accesibilidad HTTP. |
| Otros archivos privados, copias o artefactos | Sin evidencia. | Protección no evaluada. |

## Matriz de pendientes para decidir la publicación

Las prioridades siguientes corresponden al cierre de incertidumbres; no son severidades de vulnerabilidades confirmadas.

| Prioridad | Pendiente | Evidencia de cierre requerida |
| --- | --- | --- |
| Alta | **Entorno de producción**: no se dispone de evidencia del destino de publicación. | Identificación del despliegue objetivo, su arquitectura y configuración efectiva sanitizada. Documentación de diferencias frente a staging. |
| Alta | **Perímetro de red**: security group, IPv6, NAT y proxy desconocidos. | Reglas efectivas de las capas aplicables y mapa de servicios publicados. Si IPv6 no está habilitado, evidencia que lo sustente. |
| Alta | **Acceso a 8090**: alcance y función sin determinar. | Identificación del servicio y sus consumidores autorizados; comprobación posterior desde orígenes permitidos y no permitidos, considerando también rutas de proxy. Si es interno, demostrar su aislamiento del acceso público. |
| Alta | **HTTPS**: permiso en 443 sin validación del servicio. | Verificación posterior del certificado, nombre de dominio, cadena de confianza y configuración TLS del destino real. |
| Alta | **Debug y errores**: solo existe el valor de ejemplo. | Confirmación del valor efectivo de `APP_DEBUG`, incluida la configuración cacheada, y respuestas de error sin trazas ni secretos. |
| Alta | **Protección de archivos sensibles**: E1 cubre una única respuesta de staging. | Revisión de raíz pública y reglas de publicación, junto con comprobaciones autorizadas del entorno objetivo que distingan contenido real, denegación y fallback SPA. |
| Media | **Sesión y CSRF**: atributos conocidos, comportamiento no probado. | Pruebas de aceptación con token válido y rechazo con token ausente o inválido en operaciones protegidas; comprobación de invalidación de sesión y alcance de cookies. |

## Plan de comprobación posterior

1. **Reunir evidencia del despliegue objetivo**, sin solicitar valores secretos: arquitectura, reglas efectivas de red, rutas de proxy y configuración relevante sanitizada.
2. **Comprobar la superficie accesible**, desde ubicaciones autorizadas internas y externas. Registrar origen, destino, protocolo y resultado; no inferir exposición a partir del listener local.
3. **Verificar HTTPS, archivos y debug** en el entorno que se pretende publicar. Para archivos sensibles, contrastar tipo de contenido y respuesta con controles inexistentes y con el shell SPA; no usar únicamente el código HTTP como criterio.
4. **Validar el comportamiento de sesión y CSRF**. La ausencia de HttpOnly en `XSRF-TOKEN` no requiere corrección por sí sola: el cliente necesita leer ese token en el flujo habitual de Laravel.
5. **Emitir un nuevo dictamen con los resultados**, identificando qué controles se comprobaron y cuáles siguen sin cobertura. E1–E5 tampoco aportan resultados sobre autorización de aplicación, dependencias o recuperación; no deben presentarse como áreas aprobadas.

## Decisión final

**Publicación pendiente de validación.** No se acredita una fuga de `.env`, ni que 8090 sea accesible desde Internet, ni un fallo por la cookie CSRF legible por JavaScript. A la vez, las lagunas de red, configuración efectiva y producción impiden recomendar la publicación con fundamento. El cierre debe basarse en evidencia del destino real, no en el ejemplo de configuración ni en suposiciones sobre staging.
