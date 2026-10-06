# Checklist de cumplimiento legal web

Selecciona los controles pertinentes y registra para cada uno: verificado / requiere
cambio / no verificado / no aplica (motivo), con evidencia (archivo:línea, URL,
cabecera, captura o prueba). Separa local y producción.

| ID | Control | Evidencia de cierre |
|---|---|---|
| LSSI-01 | Aviso legal: titular, NIF, domicilio, email de contacto directo | Página pública enlazada desde todas las páginas con datos reales aportados por el titular |
| LSSI-02 | Datos registrales, autorización o colegiación si aplican | Presentes o «no aplica» justificado |
| LSSI-03 | Comunicaciones comerciales (art. 20-21) | Sin envíos sin consentimiento previo; baja sencilla y gratuita en cada envío |
| LSSI-04 | Condiciones de uso si hay cuentas o servicio | Texto accesible, aceptado en registro si es contractual |
| RGPD-01 | Inventario de tratamientos | Datos, finalidad, base legal, conservación, destinatarios por tratamiento |
| RGPD-02 | Política de privacidad (arts. 13-14) | Cubre todo el inventario; sin tratamientos inventados ni omitidos |
| RGPD-03 | Información por capas en formularios (art. 11 LOPDGDD) | Primera capa junto al formulario con enlace a la política |
| RGPD-04 | Consentimientos separados y no premarcados | Finalidades no necesarias con casilla propia, opcional y registrable |
| RGPD-05 | Edad mínima | 14 años en España si el servicio la exige o se basa en consentimiento |
| RGPD-06 | Ejercicio de derechos | Canal operativo, plazo de un mes, borrado/exportación de cuenta posible |
| RGPD-07 | Encargados del tratamiento (art. 28) | Contrato/DPA identificado para hosting, email, CDN, pagos, etc. |
| RGPD-08 | Transferencias internacionales (cap. V) | Proveedor fuera del EEE con adecuación (p. ej. DPF) o garantías |
| RGPD-09 | Registro de actividades (art. 30) | Documento interno existente o plan; no publicado |
| RGPD-10 | Seguridad y brechas (arts. 32-34) | Medidas proporcionadas; procedimiento de notificación en 72 h |
| RGPD-11 | DPO y EIPD | Necesidad evaluada y motivada |
| RGPD-12 | Conservación y minimización | Plazos definidos para cuentas, logs, backups; datos innecesarios eliminados |
| CK-01 | Inventario de almacenamiento en el terminal | Cookies y storage con nombre, titular, finalidad, duración y tipo |
| CK-02 | Clasificación exenta / no exenta | Justificación por cada elemento según guía AEPD vigente |
| CK-03 | Nada no exento antes del consentimiento | Prueba en navegador limpio sin interacción |
| CK-04 | Banner: aceptar y rechazar al mismo nivel | Misma capa, formato y prominencia; sin dark patterns |
| CK-05 | Configuración granular y retirada | Panel accesible en todo momento; retirar tan fácil como aceptar |
| CK-06 | Registro y renovación del consentimiento | Prueba del consentimiento; renovación según guía vigente |
| CK-07 | Política de cookies | Coincide con el inventario real; enlazada desde footer y banner |
| TP-01 | Recursos de terceros en carga inicial | Lista de dominios externos y datos enviados (IP, referer) |
| TP-02 | Autoalojamiento o consentimiento | Fuentes/CDN autoalojados o cubiertos y bloqueados hasta consentir |
| SEC-01 | Avisos sectoriales | Ej. finanzas: no asesoramiento/recomendación personalizada, riesgos, sin promesas de rentabilidad |
| SEC-02 | Coherencia UI con el aviso | Copy, CTAs y señales no contradicen el aviso sectorial |
| CON-01 | Contratación con consumidores | Información precontractual, precio, desistimiento, condiciones (solo si hay pago o contrato) |
| CON-02 | Resolución de litigios | Sin enlace a la plataforma ODR cerrada; ADR si aplica |
| DSA-01 | Contenido de terceros visible | Punto de contacto, notificación y acción si se aloja contenido público |
| ACC-01 | Ámbito EAA / Ley 11/2023 | Servicio incluido o no; exención de microempresa evaluada |
| ACC-02 | Accesibilidad de páginas legales y banner | Teclado, foco, contraste, lectores de pantalla; banner no bloquea sin opción |
| OPS-01 | Ubicación y publicación | Páginas legales públicas, sin login, con fecha de actualización |
| OPS-02 | Revisión jurídica | Puntos que requieren abogado listados |

## Decisiones delicadas

- **Analítica:** casi siempre requiere consentimiento. La exención de analítica
  propia es excepcional y depende de la guía vigente; documenta la justificación.
- **localStorage del SPA:** el art. 22.2 LSSI-CE cubre cualquier almacenamiento en
  el terminal, no solo cookies. Preferencias puramente técnicas solicitadas por el
  usuario (tema, idioma elegido) suelen ser exentas; justifícalo.
- **Logs y backups:** son tratamiento de datos (IP, emails). Inclúyelos en el
  inventario con plazos.
- **Interés legítimo:** no sirve para cookies no exentas ni para comunicaciones
  comerciales sin relación previa.
- **Persona física titular:** el aviso legal exige domicilio; señala al usuario el
  impacto en su privacidad y las alternativas legales, sin ocultar el dato exigido.
