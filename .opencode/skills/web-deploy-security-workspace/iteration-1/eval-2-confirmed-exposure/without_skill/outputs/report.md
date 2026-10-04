# Informe de seguridad previo a publicación

**Dictamen: NO APTO PARA PUBLICAR.** La evidencia del escenario confirma tres bloqueos: divulgación de secretos de producción, servicio de cálculo accesible públicamente sin autenticación y acceso de un Registered User al ledger administrativo. Deben cerrarse y verificarse antes de autorizar la publicación.

## Alcance y limitaciones

Evaluación **offline y simulada**, sobre producción Laravel/React del objetivo ficticio `app.example.test`. Se utiliza exclusivamente la evidencia E1–E6 proporcionada. No se han realizado peticiones, ejecutado comandos, inspeccionado otros archivos del proyecto ni aplicado correcciones. Las confirmaciones corresponden al escenario aportado, no a pruebas independientes realizadas para este informe.

No se proporcionaron valores de secretos y no son necesarios para este dictamen. No deben incluirse en el informe ni en las evidencias de cierre.

## Hallazgos y riesgos

| ID / evidencia | Estado y gravedad | Hallazgo y alcance demostrado | Riesgo y decisión |
|---|---|---|---|
| H1 / E1 | Confirmado; crítico | Una revisión autorizada externa obtuvo `GET /.env` con `200 text/plain`, nombres `APP_KEY` y `DB_PASSWORD` y valores presentes. Es divulgación de configuración sensible, no un mero código 200 ni un fallback SPA. | La contraseña podría permitir acceso a la base de datos donde exista conectividad y permisos; la clave de aplicación podría comprometer garantías criptográficas según su uso. Bloquea publicación. No demuestra por sí sola acceso a la base de datos ni explotación de la clave. |
| H2 / E2 | Confirmado; alto | Desde una red externa, `8090/TCP` responde a `/health` con 200 y los endpoints de cálculo son públicos sin autenticación. | Permite invocación no autorizada del cálculo y puede facilitar consumo abusivo de recursos. Bloquea publicación. No hay evidencia sobre extracción de datos, ejecución remota de código o transporte de ese puerto. |
| H3 / E3 | Confirmado; alto | `GET /api/admin/ingestion/runs` devuelve 200 y el ledger a un Registered User que no es Admin. | Fallo de autorización por rol y divulgación de información administrativa. Bloquea publicación. La evidencia no demuestra permiso para iniciar, modificar o borrar Ingestion Runs. |
| H4 / E5 | No verificado; impacto potencial alto | Se desconoce la política de backups y no existe restauración probada en la evidencia. | La capacidad de recuperación no está acreditada. No se afirma que no existan backups. Requiere evidencia y una prueba satisfactoria antes de aprobar la preparación operativa. |
| H5 / E6 | Comprobación inconclusa; gravedad desconocida | El auditor de dependencias falló por timeout de red. | No permite declarar las dependencias seguras ni afirmar vulnerabilidades concretas. La comprobación debe completarse antes del dictamen final de publicación. |

### Superficie y controles observados

| Elemento | Evidencia disponible | Conclusión limitada |
|---|---|---|
| Archivo `/.env` | E1: contenido sensible devuelto desde el exterior | Exposición confirmada; prioridad inmediata de contención. |
| Servicio `8090/TCP` | E2: `/health` y cálculo accesibles desde el exterior | Exposición externa confirmada desde la red ensayada; cálculo sin autenticación. |
| API administrativa | E3: Registered User recibe el ledger | Separación entre Registered User y Admin incumplida en este endpoint. |
| HTTPS | E4: certificado válido | Control favorable únicamente respecto a la validez del certificado. No acredita configuración TLS completa, cabeceras, cookies ni seguridad del puerto 8090. |
| Otros archivos, puertos y controles | Sin evidencia | No evaluados; no se presumen seguros ni expuestos. |

## Plan de contención y corrección propuesto

Las siguientes acciones son recomendaciones pendientes de ejecución por los responsables del sistema.

### 1. Contener las exposiciones — prioridad inmediata

- **Operaciones:** retirar el acceso público a `/.env`, revisar el directorio servido para que solo incluya los recursos públicos previstos y aplicar restricciones de archivos sensibles en las capas que entregan la respuesta. Si hay proxy o caché que haya almacenado el archivo, retirar también esa copia.
- **Operaciones y backend:** restringir `8090/TCP` a los consumidores autorizados. Definir y exigir autenticación de servicio y autorización para el cálculo; una ruta privada o un proxy no deben ofrecer una vía pública alternativa que eluda esos controles.
- **Backend:** bloquear el acceso no Admin a `/api/admin/ingestion/runs` mediante autorización del lado servidor. Revisar los endpoints administrativos relacionados para delimitar el alcance del fallo, sin asumir que todos estén afectados.
- **Responsable de seguridad:** conservar registros relevantes con acceso restringido y revisar accesos a `/.env`, al servicio de cálculo y al ledger. Delimitar la ventana de exposición cuando los registros lo permitan. La ausencia de eventos no demuestra ausencia de abuso, especialmente si la retención es insuficiente.

### 2. Sustituir los secretos expuestos de forma controlada

- Tratar `APP_KEY` y `DB_PASSWORD` como comprometidos a efectos de respuesta, aunque no se haya demostrado su uso indebido. Ocultar el archivo no invalida los valores ya divulgados.
- Rotar la credencial de base de datos, desplegar la nueva configuración en todos los consumidores y revocar la credencial anterior. Confirmar privilegios mínimos, conectividad legítima y rechazo de la credencial antigua desde un contexto autorizado.
- Inventariar qué datos persistentes, cookies, sesiones y otros mecanismos dependen de `APP_KEY`. Preparar recuperación y migración o recifrado cuando proceda antes del cambio: una sustitución improvisada puede volver ilegibles datos legítimos.
- Desplegar la nueva clave y retirar la confianza en la anterior; mantener una clave comprometida como clave de descifrado alternativa no equivale a cerrar el incidente. Invalidar o renovar los artefactos afectados según su implementación y comprobar los flujos legítimos.
- Revisar de manera autorizada si el archivo contenía otros secretos y rotarlos cuando corresponda, sin copiarlos al informe. Usar identificadores de versiones o constancias de revocación como evidencia, nunca los valores.

### 3. Resolver las comprobaciones pendientes

- **Operaciones:** documentar alcance, frecuencia, retención, protección y responsables de backups, junto con objetivos de pérdida de datos y tiempo de recuperación (RPO/RTO). Ejecutar una restauración aislada y medir integridad, pérdida de datos y duración frente a los objetivos aprobados.
- **Responsable de dependencias:** resolver el impedimento de red y repetir el análisis de dependencias sobre los artefactos y versiones que se publicarán. Registrar cobertura, fecha, herramienta, fuentes de avisos, resultado completo y resolución de vulnerabilidades relevantes. Un timeout o un análisis parcial no son un resultado satisfactorio.

## Pruebas de cierre y evidencia exigida

Estas pruebas se proponen para una revisión posterior autorizada; **no se han ejecutado en esta simulación**.

| Control | Prueba verificable | Criterio de cierre y evidencia sanitizada |
|---|---|---|
| Archivo sensible | Desde el exterior, solicitar `/.env` a las rutas de entrega aplicables y contrastar con una ruta inexistente de control. Comprobar el contenido, no solo el estado HTTP. | Respuesta denegada o archivo no servido, sin variables ni secretos; preferiblemente 403/404. Registrar fecha, ruta, estado y tipo de contenido sin conservar cuerpos sensibles. Un 200 que fuera shell SPA requiere acreditar ese contenido, no asumir fuga ni cierre por el código. |
| Rotación de secretos | Verificar despliegue de nuevas versiones, revocación de la contraseña anterior y retirada de confianza en la clave anterior; comprobar datos cifrados y flujos afectados. | Credenciales antiguas inutilizables para sus usos correspondientes y aplicación operativa. Adjuntar referencias de cambio y resultados redactados, sin secretos ni hashes derivados de contraseñas. |
| Servicio de cálculo | Probar acceso externo directo a 8090 y posibles rutas de proxy; ensayar solicitudes sin credenciales, con credenciales inválidas y con identidad de servicio autorizada. | Puerto interno no accesible desde redes no autorizadas; ninguna ruta de cálculo permite invocación anónima. El consumidor legítimo funciona. Registrar origen de prueba, ruta, resultado y regla aplicada. |
| Autorización Admin | Ensayar el endpoint con sesión ausente, Registered User y Admin usando identidades de prueba; comprobar también ausencia del ledger en respuestas denegadas. | Sesión ausente: 401; Registered User: 403, o 404 si esa es la política documentada; Admin: acceso legítimo. Pruebas de integración deben acreditar autorización servidor, no solo ocultación de interfaz. |
| Recuperación | Restaurar un backup en un entorno aislado y validar consistencia y funcionamiento. | Restauración satisfactoria dentro de los RPO/RTO aprobados; acta con identificador del backup, tiempos, comprobaciones y responsable, sin datos privados. |
| Dependencias | Ejecutar de nuevo el análisis con cobertura completa del despliegue Laravel/React y del servicio de cálculo cuando tenga dependencias separadas. | Ejecución completada, cobertura explícita y hallazgos evaluados y resueltos según criterios de publicación acordados. Conservar salida sanitizada y versiones analizadas. |

## Decisión de publicación

**Mantener bloqueada la publicación.** HTTPS con certificado válido no compensa la fuga de secretos ni los fallos de acceso. La reevaluación requiere evidencia del cierre de H1–H3, incluida la rotación, además de recuperación demostrada y análisis de dependencias completado. Con los datos actuales no se puede acreditar preparación segura para producción ni afirmar que haya ocurrido una intrusión.
