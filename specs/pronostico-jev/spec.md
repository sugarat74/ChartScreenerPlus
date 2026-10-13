# Pronóstico experimental con Jev

## Por qué

Permitir que un Registered User evalúe, desde el gráfico de un Instrument, la probabilidad estimada de que su cierre suba o baje en un plazo definido. Chartiko registrará pronósticos y resultados para medir y ajustar su eficacia antes de considerar una versión de pago. Jev es el modelo de TypeSafe AI; la integración propuesta usa su API de decisiones tipadas, no un agente de AnyTool.

## Alcance

- Primera prueba propuesta: horizontes de **1 y 5 sesiones bursátiles** desde la última Daily Bar disponible. Esta selección queda pendiente de confirmación del usuario.
- El usuario introduce un **porcentaje mínimo de probabilidad** para destacar una dirección. Jev estima las probabilidades; el porcentaje introducido no se presenta como una estimación del modelo.
- Evento a resolver: comparar el cierre de la sesión objetivo con el cierre de la sesión de referencia. `up` si es mayor, `down` si es menor, `flat` si es igual. Las tres clases son excluyentes.
- Acceso inicial para cuentas autenticadas, sin suscripción ni cobro. El gráfico, las velas y los Signals actuales siguen siendo públicos.
- Los resultados son experimentales y se verifican con los cierres posteriores. No se crean órdenes, consejos de compra/venta, alertas ni cotizaciones intradía.

## Requisitos

### R1. Acceso de Registered Users

Chartiko DEBE permitir consultar el pronóstico únicamente con una sesión autenticada y DEBE aplicar la restricción en la API. NO DEBE exigir un plan de pago en esta iteración.

**Escenario:** DADO un Visitor en el gráfico / CUANDO solicita un pronóstico por la interfaz o la API / ENTONCES ve la invitación a iniciar sesión y la API devuelve 401 sin consultar Jev.

**Escenario:** DADO un Registered User autenticado / CUANDO solicita un pronóstico válido / ENTONCES puede recibirlo sin una comprobación de suscripción.

### R2. Pregunta y plazo verificables

El pronóstico DEBE referirse al cierre de un Instrument en una fecha de referencia y a un horizonte de sesiones bursátiles soportado; DEBE devolver probabilidades separadas para `up`, `down` y `flat`, con fecha de referencia y fecha objetivo identificables. NO DEBE describir un movimiento intradía.

**Escenario:** DADO un cierre de referencia de 100 y horizonte de 5 sesiones / CUANDO se registra una predicción / ENTONCES su pregunta queda fijada como la comparación entre ese cierre y el cierre de la quinta sesión bursátil posterior, con las tres clases definidas arriba.

### R3. Umbral elegido por el usuario

Chartiko DEBE aceptar un porcentaje mínimo de probabilidad de 0 a 100 y destacar una dirección solo si la probabilidad estimada de `up` o `down` alcanza ese umbral. Si `flat` es la clase más probable, o ninguna dirección alcanza el umbral, DEBE mostrar «sin señal suficiente» en lugar de forzar subida o bajada. El umbral NO DEBE alterar las probabilidades devueltas por Jev.

**Escenario:** DADO `up = 0,62`, `down = 0,28`, `flat = 0,10` / CUANDO el usuario fija 70 % / ENTONCES la interfaz muestra «sin señal suficiente» y conserva 62 %, 28 % y 10 % como probabilidades estimadas.

### R4. Datos disponibles y fecha de corte

Chartiko DEBE construir el estado enviado a Jev a partir de Daily Bars, Indicator Snapshots y Signals ya almacenados hasta la fecha de referencia, con una ventana de historial acotada. NO DEBE enviar identidad, correo ni datos privados del Registered User, ni incluir datos posteriores a la fecha de referencia.

**Escenario:** DADO un Instrument con barras hasta el 12 de octubre / CUANDO se genera el estado del pronóstico con referencia en ese día / ENTONCES ninguna barra, indicador o Signal posterior figura en la solicitud a Jev.

### R5. Indisponibilidad honesta

Chartiko DEBE devolver un estado de pronóstico no disponible si faltan datos necesarios, la clave del proveedor, la respuesta válida o la conexión; NO DEBE inventar un porcentaje ni reutilizar silenciosamente una predicción de otra fecha o versión.

**Escenario:** DADO un Instrument sin Indicator Snapshot utilizable / CUANDO el usuario solicita un pronóstico / ENTONCES ve una explicación de indisponibilidad y no aparece porcentaje alguno.

### R6. Registro reproducible

Chartiko DEBE conservar cada pronóstico emitido con Instrument, fecha y cierre de referencia, horizonte, probabilidades, versión del modelo y versión de la pregunta/estado; las solicitudes equivalentes DEBEN reutilizar el mismo pronóstico en vez de generar valores distintos para el mismo caso. La preferencia de umbral del usuario NO DEBE modificar el pronóstico guardado.

**Escenario:** DADO un pronóstico ya guardado para el mismo Instrument, fecha, horizonte y versiones / CUANDO otro Registered User cambia solo el umbral / ENTONCES recibe las mismas probabilidades y cambia únicamente la indicación visible.

### R7. Resultado y eficacia

Chartiko DEBE resolver cada pronóstico cuando exista la Daily Bar de la sesión objetivo y DEBE ofrecer al Admin un informe que distinga número de casos, cobertura del umbral, acierto direccional y calibración por rangos de probabilidad, segmentados al menos por horizonte y versión. NO DEBE contabilizar como fallo una predicción cuyo cierre objetivo aún no existe.

**Escenario:** DADO un pronóstico de 5 sesiones pendiente / CUANDO se ingiere el cierre de la quinta sesión bursátil posterior / ENTONCES se guarda `up`, `down` o `flat` como resultado y el caso entra en las métricas de esa versión.

### R8. Presentación prudente y bilingüe

La interfaz DEBE mostrar que es una estimación experimental, la fecha de los datos, el plazo y el resultado de la comparación con el umbral en español e inglés. NO DEBE presentarla como garantía de rentabilidad, recomendación de inversión o probabilidad de beneficio de una operación.

**Escenario:** DADO un Registered User que abre el gráfico en inglés / CUANDO ve un pronóstico / ENTONCES encuentra las tres probabilidades, el horizonte, la fecha de referencia y el aviso experimental en inglés.

### R9. Control de uso y secreto

Chartiko DEBE mantener la clave de Jev solo en el servidor, limitar las solicitudes para proteger el coste y registrar fallos operativos sin exponer credenciales ni el estado completo en logs públicos.

**Escenario:** DADO un usuario que repite rápidamente una solicitud ya calculada / CUANDO el servidor la atiende / ENTONCES devuelve el registro existente o aplica el límite configurado sin efectuar una nueva llamada al proveedor.

## Fuera de alcance

- Pasarela de pago, planes o permisos de suscripción; se revisarán más adelante.
- Ejecución de órdenes, consejo de compra/venta, alertas automáticas y datos intradía.
- Entrenar o ajustar internamente los pesos de Jev. «Ajustar eficacia» aquí significa comparar versiones de estado/pregunta y umbrales con evidencia, sin reescribir resultados anteriores.

## Condiciones antes de exponer porcentajes como fiables

- Validar con datos separados en el tiempo, sin usar información futura ni elegir retrospectivamente la mejor versión sobre el mismo conjunto de evaluación.
- Comparar con una referencia simple; publicar junto al informe el tamaño de muestra, calibración y cobertura por umbral. Un porcentaje devuelto por Jev no se da por calibrado para acciones solo porque el proveedor lo denomine probabilístico.
- Confirmar que las licencias de la fuente de mercado y los términos del proveedor permiten enviar el estado a TypeSafe AI. Hasta entonces, las pruebas pueden usar fixtures o datos con derechos confirmados.

## Referencia del proveedor

- [TypeSafe AI: Jev, estado y preguntas tipadas](https://docs.typesafe.ai/introduction/quickstart). La disponibilidad, el contrato y el tratamiento de datos deben verificarse de nuevo al implementar.
