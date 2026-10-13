# Tareas: pronostico-jev

- [x] 1. Cerrar los plazos iniciales, insumos mínimos, límite de uso y condiciones de envío de datos al proveedor; dejar constancia de las decisiones abiertas del diseño. (R2, R4, R9)
- [x] 2. Definir y migrar el registro inmutable de pronósticos y resultados con clave lógica por Instrument, fecha, horizonte y versiones. (R6, R7)
- [x] 3. Construir un estado acotado y reproducible desde Daily Bars, Indicator Snapshots y Signals con corte temporal verificable. (R2, R4, R5)
- [x] 4. Implementar el adaptador Jev en el servidor con clave privada, respuesta tipada validada, timeout y estados de error explícitos; probarlo con fixtures sin red. (R2, R5, R9)
- [x] 5. Añadir el servicio de deduplicación y el endpoint autenticado con validación de ticker, horizonte y umbral, sin cobro ni cambios al endpoint público del gráfico. (R1, R3, R6, R9)
- [x] 6. Resolver pronósticos vencidos tras nuevas Daily Bars con cierre y clase observados, de forma idempotente y sin evaluar pendientes. (R2, R7)
- [x] 7. Crear el informe Admin por plazo y versión con tamaño de muestra, cobertura, acierto y calibración, separando evaluación histórica y seguimiento prospectivo. (R7)
- [x] 8. Añadir al gráfico el panel para cuentas autenticadas y la invitación de inicio de sesión para Visitors, con textos es/en y estados experimental, insuficiente e indisponible. (R1, R3, R5, R8)
- [x] 9. Verificar autorización 401, ausencia de datos futuros, clases y umbral, deduplicación, errores del proveedor, resolución, métricas y UI bilingüe; ejecutar `.\init.ps1` y registrar evidencia. (R1, R2, R3, R4, R5, R6, R7, R8, R9)
