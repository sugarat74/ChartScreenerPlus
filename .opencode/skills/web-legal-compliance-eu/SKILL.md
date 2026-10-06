---
name: web-legal-compliance-eu
description: >-
  Audita y prepara el cumplimiento legal de un sitio o aplicación web dirigida a
  España/UE: aviso legal (LSSI-CE), privacidad (RGPD/LOPDGDD), cookies y
  consentimiento (ePrivacy, guía AEPD), recursos de terceros y transferencias,
  avisos sectoriales, consumo y accesibilidad (EAA). Úsala para auditar un sitio,
  redactar borradores de textos legales o implementar banner, páginas y
  cláusulas. No sustituye la revisión de un abogado.
---

# Cumplimiento legal web (España / UE)

Entrega un diagnóstico con evidencia, borradores de textos legales basados en el
tratamiento real y, si se pide, los cambios implementados y verificados. El
resultado es una base técnica sólida, no asesoramiento jurídico: indica siempre
qué debe revisar un profesional.

## Alcance

Identifica titular (persona física, autónomo o sociedad), dominio, público y país,
idioma, stack, entorno y accesos. Lee instrucciones del repo, brief, arquitectura,
modelo de usuarios/acceso y DESIGN.md si cambias UI. Inspecciona el estado actual
del código y del sitio servido, no solo documentos o informes antiguos.

- **Auditar:** inspección e informe; sin cambios de producto.
- **Redactar:** borradores de aviso legal, privacidad, cookies, cláusulas y avisos
  sectoriales a partir de [assets/legal-texts.md](assets/legal-texts.md).
- **Implementar:** páginas, enlaces, cláusulas en formularios, banner/panel de
  cookies y bloqueo previo de scripts no exentos, con pruebas.

Los datos identificativos del titular (nombre o razón social, NIF/CIF, domicilio,
email, registro mercantil, colegiación/autorización) los aporta el usuario. **No
los inventes ni uses placeholders en textos publicados**: deja el campo marcado
como pendiente y bloquea la publicación de esa página. Lo mismo para plazos de
conservación, encargados, DPO o base legal que no se puedan deducir del código.

Lee [references/checklist.md](references/checklist.md) para elegir controles.
Consulta [references/sources.md](references/sources.md) cuando la conclusión
dependa de una norma o criterio concreto; las guías de autoridades cambian:
comprueba la versión vigente y registra fecha y enlace. Sin red, marca la
comprobación como pendiente.

## Ejecución

1. **Inventario de tratamientos.** Recorre formularios, registro/login, contacto,
   newsletter, pagos, logs, backups, colas y analítica. Para cada uno: datos,
   finalidad, base legal (art. 6 RGPD), conservación, destinatarios/encargados
   (hosting, email, CDN, pagos) y transferencias fuera del EEE.
2. **Inventario de almacenamiento en el terminal.** Cookies, localStorage,
   sessionStorage, IndexedDB, píxeles y fingerprinting, propios y de terceros.
   Obtén evidencia real: cabeceras `Set-Cookie`, código que escribe en storage
   y, si hay navegador, el estado tras la primera carga *sin interactuar*.
   Clasifica cada uno: técnica/necesaria (exenta), preferencias, analítica,
   publicidad. Exentas solo si son estrictamente necesarias para el servicio
   solicitado por el usuario; analítica propia solo exenta en los supuestos
   restrictivos de la guía vigente de la AEPD.
3. **Recursos de terceros.** Fuentes, CDN, mapas, vídeos, captchas, widgets y
   analítica que reciben la IP del visitante al cargar la página. Prefiere
   autoalojar; si no, cubre en privacidad y, si almacenan en el terminal o no son
   necesarios, condiciona a consentimiento.
4. **Decide el banner.** Sin almacenamiento no exento y sin terceros que lo
   requieran: no hace falta banner; basta una política de cookies informativa.
   Si hace falta: primera capa con «Aceptar» y «Rechazar» en mismo nivel, formato
   y lugar; configuración granular sin casillas premarcadas; nada no exento antes
   del consentimiento; retirar tan fácil como dar; registro del consentimiento;
   sin cookie walls salvo los supuestos admitidos por la guía vigente.
5. **Textos y puntos de información.** Aviso legal accesible desde todas las
   páginas; política de privacidad completa (arts. 13–14 RGPD, información por
   capas art. 11 LOPDGDD); primera capa junto a cada formulario que recoja datos;
   consentimiento separado y no premarcado para finalidades no necesarias
   (newsletter, comunicaciones comerciales art. 21 LSSI-CE); edad mínima (14
   años en España) si aplica; condiciones de uso si hay cuentas o contenido.
6. **Derechos y obligaciones internas.** Canal para ejercer derechos, registro de
   actividades (art. 30), contratos de encargo (art. 28), medidas de seguridad y
   procedimiento de brechas (72 h, art. 33). Evalúa si hace falta DPO (art. 37
   RGPD y art. 34 LOPDGDD) o EIPD. Son documentos internos: no se publican.
7. **Sector, consumo y accesibilidad.** Avisos sectoriales (p. ej. contenido
   financiero no constituye asesoramiento), consumo si hay contratación o pago
   (información precontractual, desistimiento, condiciones), DSA si se alojan
   contenidos de terceros visibles, y si la EAA/Ley 11/2023 alcanza al servicio
   (con exención de microempresas en servicios). WCAG como buena práctica aunque
   no sea obligatorio.
8. **Implementa y verifica** (si se pidió): enlaces en footer y formularios,
   rutas accesibles sin login, metadatos `noindex` solo si se decide, banner con
   bloqueo previo real y prueba en navegador limpio. Ejecuta las verificaciones
   del repo. Una herramienta fallida deja el control «no verificado».
9. **Informe** con [assets/compliance-report.md](assets/compliance-report.md):
   inventarios, matriz de controles, hallazgos priorizados, textos producidos,
   datos pendientes del titular y puntos de revisión jurídica. Guárdalo fuera de
   artefactos públicos y actualiza el seguimiento del proyecto.

## Reglas

- No afirmes «cumple» ni «exento» sin evidencia; usa «verificado», «requiere
  cambio», «no verificado» o «no aplica (motivo)».
- No copies textos legales genéricos que describan tratamientos inexistentes ni
  omitan los reales; cada frase debe corresponder al inventario.
- No añadas analítica, CMP de terceros ni dependencias nuevas sin pedirlo: la
  opción con menos datos suele ser también la de menos obligaciones.
- No publiques datos personales del titular más allá de lo exigido, ni secretos,
  logs o informes internos. Los documentos internos (registro, contratos) no van
  al sitio público.
- No despliegues, envíes comunicaciones ni contactes con autoridades o terceros
  sin autorización específica.
- La plataforma europea ODR cerró el 20-07-2025: no añadas enlaces a ella; para
  consumidores informa de entidades de resolución alternativa si aplica.
- Señala cuándo hace falta abogado: sociedad o actividad regulada, pagos o
  suscripciones, transferencias internacionales relevantes, perfiles o
  decisiones automatizadas, menores, datos de categorías especiales, EIPD.

## ChartScreenPlus / Chartiko

- Producto público Chartiko en `https://www.chartiko.com`, VPS en OVH (encargado
  del tratamiento; verificar su DPA vigente). Laravel + React SPA + engine Python.
- Tratamientos conocidos: registro/login de Registered Users (email, contraseña
  hash), Watchlists y Saved Screeners, sesiones/cola/cache en base de datos,
  logs del servidor y backups. Revisa el código por si hay más.
- Almacenamiento conocido: cookie de sesión de Laravel y `XSRF-TOKEN` (técnicas).
  Verifica `localStorage` del SPA y cualquier recurso externo antes de concluir
  que no hace falta banner. Fuentes y scripts se sirven desde el propio dominio.
- Aviso sectorial obligatorio en la práctica: Screener, Candidates y Signals son
  información técnica general, no asesoramiento ni recomendación personalizada
  de inversión; no prometas rentabilidad. Revisa que la UI no lo contradiga.
- Respeta CONTEXT.md, DESIGN.md, PowerShell 5.1 y la regla de no mostrar EOD
  fuera de Admin. Las páginas legales son públicas y no requieren login.
- El alcance funcional de cumplimiento para Chartiko es la feature
  `legal-compliance-eu`; esta skill no la sustituye ni la marca como hecha.

## Cierre

Termina con uno de: «borrador listo para revisión jurídica», «implementado
localmente, publicación pendiente», «bloqueado por datos del titular» o
«requiere cambios». Lista los datos que faltan y quién debe aportarlos.
