# Diseño: pronostico-jev

## Arquitectura

- **React SPA:** un panel discreto en `InstrumentChartPage` solicita el pronóstico tras iniciar sesión. (Nota 2026-10-15, `premium-panels-auth-only`: el panel no se renderiza para un Visitor ni mientras se resuelve la sesión.) El gráfico público mantiene su ruta y su API actual; el panel muestra el umbral, el horizonte, las probabilidades y los estados de carga/error. Todos los textos pertenecen a `frontend/src/i18n/messages/{es,en}.ts` y siguen `DESIGN.md`.
- **Laravel:** una ruta nueva bajo `auth:sanctum` valida ticker, horizonte y umbral, toma un corte inmutable de las Daily Bars, Indicator Snapshots y Signals almacenados y devuelve un resultado tipado. El endpoint público `GET /api/instruments/{ticker}` no incorpora probabilidades. La clave del proveedor vive en configuración de servidor, fuera del cliente y del repositorio.
- **Jev:** un adaptador de servidor llama a `POST /v1/systemone` de TypeSafe AI con un `state` estructurado y una pregunta `choice` de tres resultados (`up`, `down`, `flat`). Se almacenan las tres probabilidades originales, la versión concreta de Jev y la versión local de la pregunta/estado. El umbral del usuario se aplica después; no forma parte de la pregunta enviada al modelo.
- **Evaluación:** después de una actualización de Daily Bars, un proceso idempotente resuelve los pronósticos cuya sesión objetivo ya tiene cierre. Un informe Admin agrega resultados por horizonte y versión. La evaluación histórica reproduce únicamente los datos disponibles hasta cada fecha de corte y reserva tramos temporales posteriores para comprobar ajustes.

## Librerías

Se proponen los mecanismos ya presentes: cliente HTTP de Laravel, Sanctum, pruebas Laravel y Vitest, React y los cálculos de mercado almacenados. No se fija ninguna librería adicional ni un SDK de Jev en esta spec; el contrato HTTP oficial se revisará al implementar.

## Restricciones de seguridad

- `auth:sanctum` protege la ruta de pronóstico y el servidor repite toda validación; ocultar el panel a Visitors es solo presentación.
- Limitar por cuenta y reutilizar resultados equivalentes para controlar abuso/coste; los errores del proveedor no incluyen secretos ni payloads completos para el cliente.
- No enviar a TypeSafe identidad, correo, Watchlist, Saved Screeners ni identificadores de sesión. Revisar la licencia de la fuente de datos de mercado y las condiciones de tratamiento del proveedor antes de usar producción.
- La autorización actual es «cuenta autenticada», sin tabla de planes ni pasarela. Una futura restricción de pago debe poder añadirse como una política separada sin cambiar el significado del pronóstico.
- La salida de Jev se valida: clases exactas, probabilidades numéricas finitas entre 0 y 1 y suma coherente antes de persistir o mostrar.

## Cambios de base de datos

La medición pedida requiere persistencia. El diseño lógico conserva, como mínimo: Instrument, fecha/cierre de referencia, horizonte, fecha objetivo, corte de datos o huella, versión del modelo, versión de pregunta/estado, probabilidades `up/down/flat`, estado pendiente/resuelto, cierre y clase observados, y marcas temporales. Una clave lógica impide duplicar un mismo caso por Instrument, fecha, horizonte y versiones. El umbral elegido por cada usuario no se guarda como parte del pronóstico compartido. La migración y nombres físicos se concretan al implementar siguiendo las convenciones actuales del repositorio.

## Decisiones de implementación

1. **Semántica:** «sube/baja» compara cierres, no máximos intradía ni rentabilidad después de costes. `flat` es igualdad exacta del cierre almacenado. El horizonte se cuenta en sesiones bursátiles posteriores con Daily Bars válidas; los fines de semana y festivos no cuentan.
2. **Horizontes iniciales propuestos:** 1 y 5 sesiones. El usuario debe confirmar esta elección antes de implementar. Cualquier otro horizonte requiere ampliar explícitamente la lista soportada y sus métricas.
3. **Umbral:** valor de 0 a 100 % elegido por el usuario, inclusivo. Se destaca únicamente la clase direccional de mayor probabilidad si alcanza el umbral; en otro caso se muestra «sin señal suficiente». Las tres probabilidades se muestran como estimaciones experimentales incluso cuando no se destaca ninguna dirección.
4. **Datos:** reutilizar la ventana acotada de barras del gráfico (252 sesiones como referencia actual), el último Indicator Snapshot y Signals del mismo corte. La construcción debe ser determinista y no incluir datos posteriores al cierre de referencia. Un registro sin insumos suficientes produce «no disponible».
5. **Reutilización:** para una combinación idéntica de Instrument, fecha de corte, horizonte y versiones se entrega un único resultado almacenado. Un cambio de umbral no llama a Jev. Al cambiar pregunta/modelo se crea una versión nueva; las anteriores permanecen para evaluación.
6. **Fallo:** tiempo de espera, credencial ausente, respuesta inválida o datos insuficientes devuelven un estado explícito sin porcentaje. Un fallo del proveedor no cambia los Signals deterministas ni el resto del gráfico.
7. **Métricas:** informe con denominador y fecha de corte, acierto de la clase máxima, cobertura y acierto cuando se aplica un umbral, Brier multiclase y tabla de calibración por rangos de probabilidad, por horizonte y versión. Separar muestras históricas de seguimiento prospectivo; no evaluar pronósticos pendientes ni reescribirlos después del resultado.
8. **Verificación:** fixtures sin red para fecha de corte, fin de semana/festivo, los tres resultados, umbral inclusivo, deduplicación, errores Jev, autenticación 401 y versión; prueba de resolución idempotente y de métricas conocidas a mano; pruebas de UI es/en y `./init.ps1` (en Windows, `.\init.ps1`). Una comprobación manual con Jev real requiere credencial de prueba y datos cuyo envío esté autorizado.

## Decisiones abiertas antes de implementar

- Confirmar los horizontes iniciales (1 y 5 sesiones propuestos).
- Definir el conjunto mínimo de insumos y la ventana de historia si los 252 bars actuales no son suficientes o resultan excesivos para coste/calidad.
- Fijar el límite de peticiones por cuenta y el plazo de retención de los registros tras medir volumen y condiciones del proveedor.
- Confirmar licencia/transferencia de datos y disponibilidad de una clave TypeSafe AI. Sin ello, la integración externa queda inactiva y se prueba con fixtures.

## Referencias

- [API y tipos de pregunta de TypeSafe AI](https://docs.typesafe.ai/introduction/quickstart).
- `docs/user-and-access-model.md`, `docs/domain-model.md`, `docs/technical-discovery.md`, `DESIGN.md`, `routes/api.php`, `frontend/src/pages/InstrumentChartPage.tsx`.
