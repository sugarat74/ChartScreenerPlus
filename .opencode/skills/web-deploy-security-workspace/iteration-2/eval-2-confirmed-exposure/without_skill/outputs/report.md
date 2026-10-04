# Informe de seguridad previo a publicación

**Dictamen: NO APTO PARA PUBLICAR.** La evidencia aportada confirma divulgación de secretos, acceso público sin autenticación al servicio de cálculo y autorización administrativa defectuosa. Son bloqueos de publicación. La recuperación y el análisis de dependencias tampoco están validados.

## Alcance

Evaluación simulada offline de producción Laravel/React en el objetivo ficticio `app.example.test`, basada exclusivamente en E1–E6. No constituye una auditoría real ni una comprobación independiente del despliegue. No se han ejecutado comandos, realizado conexiones ni corregido el sistema. Las acciones y pruebas siguientes quedan propuestas para los responsables autorizados.

No se necesitan los valores de los secretos. Deben permanecer fuera de este informe, de los tickets y de las evidencias compartidas.

## Evidencia, riesgos y prioridades

| Ref. | Evidencia del escenario | Evaluación y riesgo | Prioridad |
|---|---|---|---|
| E1 | Una revisión externa autorizada obtuvo `GET /.env` → `200 text/plain`, con `APP_KEY` y `DB_PASSWORD` y valores presentes. | **Divulgación de secretos confirmada.** El contenido sensible, y no únicamente el 200, demuestra la fuga. Puede comprometer usos criptográficos de la aplicación y permitir acceso a la base de datos si existe conectividad y la credencial tiene permisos. No demuestra por sí solo que esos accesos o abusos hayan ocurrido. | Crítica; bloqueo inmediato. |
| E2 | Desde una red externa, `8090/TCP` responde a `/health` con 200; los endpoints de cálculo son públicos sin autenticación. | **Acceso externo y cálculo anónimo confirmados.** Posible consumo no autorizado de recursos y abuso de funciones expuestas. El resultado de `/health` por sí solo no sería suficiente para afirmar acceso al cálculo, pero la evidencia lo confirma expresamente. No acredita ejecución de código ni acceso a otros datos. | Alta; bloqueo. |
| E3 | Un Registered User no Admin recibe 200 y el ledger al consultar `GET /api/admin/ingestion/runs`. | **Fallo de autorización por rol confirmado.** Permite lectura indebida de información administrativa. No demuestra capacidad de iniciar, alterar o eliminar Ingestion Runs. | Alta; bloqueo. |
| E4 | HTTPS tiene certificado válido. | Control favorable, limitado a la validez del certificado. No acredita toda la configuración TLS, la seguridad de cookies ni el transporte utilizado en 8090. No compensa E1–E3. | Sin hallazgo negativo demostrado en este punto. |
| E5 | No se conoce la política de backups ni hay restauración probada. | **Recuperación no acreditada.** No permite afirmar que no existan backups. Sin prueba de recuperación, se desconoce si pueden cumplirse los objetivos de pérdida de datos y tiempo de recuperación. | Alta prioridad de verificación operativa. |
| E6 | El auditor de dependencias falló por timeout de red. | **Análisis inconcluso.** No hay base para declarar dependencias seguras ni para atribuirles vulnerabilidades concretas. | Comprobación pendiente para aprobar publicación. |

### Superficie conocida

| Superficie | Estado demostrado | Límites de la evidencia |
|---|---|---|
| Archivo `/.env` | Accesible desde fuera y con secretos presentes. | No se conoce la ventana de exposición ni si hubo descargas por terceros. |
| Puerto `8090/TCP` | Accesible desde la red externa ensayada; cálculo sin autenticación. | No se dispone de topología, reglas completas, cobertura IPv6 ni listado de todos los endpoints. Esto no invalida la exposición ya confirmada. |
| API administrativa | El endpoint de lectura del ledger admite un rol no Admin. | Otros endpoints y operaciones no están evaluados. |
| HTTPS | Certificado válido. | El prompt no precisa el puerto ni aporta una evaluación TLS completa. |
| Otros archivos y puertos | Sin evidencia. | No cabe declararlos protegidos o expuestos. |

## Plan propuesto

### 1. Contención inmediata

**Responsables: operaciones, backend y responsable de seguridad.**

1. Retirar el acceso externo a `/.env`. Revisar el directorio público y las reglas de entrega de archivos sensibles. Si hay copias en cachés o proxies, invalidarlas también.
2. Restringir `8090/TCP` a los consumidores autorizados. Aplicar autenticación de servicio y autorización en el cálculo; revisar que ninguna ruta alternativa de proxy permita eludirlas.
3. Restringir el endpoint del ledger a Admin mediante controles del lado servidor. Revisar las rutas administrativas relacionadas para determinar el alcance real del defecto.
4. Preservar con acceso restringido los registros relevantes y buscar accesos a `/.env`, al cálculo y al ledger. Registrar las limitaciones de retención. No interpretar ausencia de registros como prueba de ausencia de explotación.

### 2. Rotación y recuperación de confianza

**Responsables: operaciones, backend y administración de base de datos.**

- Tratar las credenciales divulgadas como comprometidas a efectos de respuesta. Denegar nuevas descargas no revoca las copias ya obtenidas.
- Rotar `DB_PASSWORD`, actualizar los consumidores legítimos y revocar la credencial antigua. Confirmar privilegios mínimos y comprobar tanto el funcionamiento legítimo como el rechazo del valor anterior desde un contexto autorizado.
- Inventariar los usos de `APP_KEY` antes de sustituirla: datos cifrados persistentes, cookies, sesiones u otros artefactos que dependan de ella según la implementación. Preparar un procedimiento controlado de recuperación y recifrado cuando corresponda, evitando pérdida de acceso a datos legítimos.
- Desplegar la nueva clave, invalidar los artefactos afectados cuando proceda y retirar la confianza operativa en la clave expuesta. Conservarla como clave de compatibilidad indefinidamente no cierra el riesgo.
- Revisar de forma autorizada si se divulgaron otros secretos en el archivo y extender la rotación a los identificados. No adjuntar sus valores a las pruebas de cierre.

### 3. Completar preparación operativa

**Responsables: operaciones y mantenimiento de dependencias.**

- Documentar alcance, periodicidad, retención, protección y responsables de backups, junto con objetivos RPO y RTO. Restaurar en un entorno aislado y validar integridad y funcionamiento, midiendo pérdida de datos y tiempo de recuperación.
- Resolver el impedimento del auditor y repetir el análisis sobre las versiones y artefactos previstos para publicar, cubriendo Laravel, React y las dependencias del servicio de cálculo que correspondan. Conservar resultado completo, cobertura y fecha. Evaluar y resolver los hallazgos conforme a los criterios de publicación acordados; un timeout no es un resultado satisfactorio.

## Verificación de cierre

Las pruebas deben realizarse después de las correcciones y por personal autorizado. Esta evaluación no las ejecuta.

| Bloque o pendiente | Prueba | Resultado exigido | Evidencia sanitizada |
|---|---|---|---|
| Fuga de `/.env` | Solicitar desde fuera la ruta y comprobar cuerpo y cabeceras; contrastar con una ruta inexistente para detectar fallback SPA. Revisar las capas de entrega aplicables. | Archivo no servido, preferiblemente 403/404, sin nombres y valores sensibles. Un código HTTP aislado no demuestra cierre; si hay fallback, acreditar que el cuerpo solo es el shell público. | Fecha, origen, ruta, estado, tipo de contenido y conclusión sobre el cuerpo, sin guardar secretos. |
| Rotación | Verificar nuevas versiones desplegadas, revocación de credenciales antiguas y retirada de confianza en la clave expuesta; probar flujos y datos cifrados afectados. | Valores antiguos inutilizables para sus usos correspondientes y aplicación legítima operativa. | Referencias de cambio y versiones de secretos, resultados de pruebas y constancia de revocación; sin valores. |
| Cálculo | Probar acceso desde redes no autorizadas a 8090 y a rutas alternativas; probar solicitudes anónimas, credenciales inválidas y consumidor legítimo. | Servicio interno inaccesible desde redes no autorizadas, ninguna invocación anónima de cálculo y funcionamiento del consumidor autorizado. | Matriz origen/destino/ruta/resultado, reglas relevantes redactadas y respuestas sin tokens ni datos privados. |
| Rol Admin | Consultar el ledger sin sesión, con Registered User y con Admin. Añadir pruebas de integración del control servidor. | Sin sesión: 401; Registered User: 403, o 404 si existe una política documentada de ocultación; Admin: acceso legítimo. Respuestas denegadas sin ledger. | Identificadores de casos, rol, estado y resultado; sin cookies, tokens ni contenido del ledger. |
| Backups | Restaurar un backup representativo en aislamiento y validar consistencia y arranque de los flujos necesarios. | Recuperación satisfactoria dentro de los RPO/RTO aprobados. | Acta con identificador de backup, tiempos, validaciones y responsable, sin datos de producción. |
| Dependencias | Repetir el análisis con cobertura completa y fuentes de avisos disponibles. | Ejecución concluida; vulnerabilidades y excepciones evaluadas conforme a los criterios de publicación, sin errores de cobertura pendientes. | Herramienta, fecha, versiones analizadas, salida sanitizada y seguimiento de hallazgos. |

## Condición para reconsiderar el dictamen

Mantener **NO APTO PARA PUBLICAR** hasta acreditar el cierre de las tres exposiciones confirmadas, incluida la rotación de secretos, y completar la validación de recuperación y dependencias. La aprobación posterior requiere revisar esas nuevas evidencias; no puede deducirse de este informe ni del certificado HTTPS válido.
