import type { LegalTexts } from './types.ts'

/**
 * Textos legales en español (fuente). Borrador técnico pendiente de revisión
 * jurídica; ver docs/specs/legal-compliance-eu.md antes de cambiar una frase.
 */
export const legalEs: LegalTexts = {
  privacy: {
    title: 'Política de privacidad',
    intro:
      'Esta política explica qué datos personales trata Chartiko, para qué, con qué base jurídica y durante cuánto tiempo, y cómo puedes ejercer tus derechos.',
    sections: [
      {
        heading: 'Responsable del tratamiento',
        items: [
          'Responsable: {ownerName}',
          'Contacto y protección de datos: {ownerEmail}',
        ],
      },
      {
        heading: 'Cuenta de usuario',
        paragraphs: [
          'Si creas una cuenta tratamos tu nombre, tu email y tu contraseña, que se guarda transformada mediante una función hash y nunca en claro. También guardamos los Screeners y la Watchlist que decides conservar.',
        ],
        accountEmail: [
          'Al registrarte te enviamos un email para confirmar que la dirección es tuya, con un enlace que caduca a las 24 horas. Hasta que la confirmes, la cuenta solo permite reenviar ese email, gestionarla o eliminarla. Guardamos la fecha de confirmación.',
        ],
        items: [
          'Finalidad: crear y mantener tu cuenta y ofrecerte las funciones de usuario registrado.',
          'Base jurídica: ejecución del contrato de uso del servicio que aceptas al registrarte (art. 6.1.b RGPD).',
          'Conservación: mientras mantengas la cuenta. Si la eliminas desde la página Cuenta o pides su supresión, la borramos de inmediato junto con tu Watchlist, tus Screeners guardados, tus alertas, tus notificaciones y tus sesiones; las copias de seguridad pueden conservarla hasta {backupDays} días más y el registro de actividad de acceso conserva el email hasta que se borra, a los {activityDays} días.',
        ],
      },
      {
        heading: 'Alertas y notificaciones',
        paragraphs: [
          'Si creas alertas guardamos su configuración (el Screener guardado o los tipos de señal o de patrón que eliges para tu Watchlist) y, tras cada actualización diaria de datos, las notificaciones con los instrumentos que las han activado.',
        ],
        emailDigest: [
          'Si activas en tu Portal el resumen diario por email (está desactivado si no lo eliges), guardamos cuándo lo activaste y el idioma del email, y los días en que tus alertas detectan algo te enviamos a tu dirección de correo un único email con sus avisos, después de la actualización diaria de datos. El email no lleva píxeles ni enlaces de seguimiento. Puedes darte de baja con el enlace del propio email, sin iniciar sesión, o desde tu Portal. Guardamos un registro de cada envío (fecha de los datos, número de avisos y hora de envío) que se borra a los {emailDigestDays} días.',
        ],
        items: [
          'Finalidad: avisarte dentro de Chartiko de los cambios que has pedido seguir.',
          'Base jurídica: ejecución del contrato de uso del servicio (art. 6.1.b RGPD).',
          'Conservación: las alertas, mientras las mantengas; las notificaciones se borran automáticamente a los {notificationDays} días.',
        ],
      },
      {
        heading: 'Sesiones y seguridad del acceso',
        paragraphs: [
          'Para mantener tu sesión y proteger las cuentas registramos la dirección IP, el navegador (agente de usuario) y la hora de la última actividad de cada sesión, así como los inicios de sesión, los intentos fallidos (con el email introducido, que puede no corresponder a ninguna cuenta), los cierres de sesión, las sesiones cerradas por la administración y la eliminación de cuentas (solo con el email). Los visitantes sin cuenta también tienen una sesión técnica con la dirección IP, el navegador y la última actividad.',
        ],
        items: [
          'Finalidad: mantener la sesión, detectar accesos indebidos o intentos repetidos y permitir que la administración cierre sesiones abiertas. No se usa para publicidad ni para seguir tu navegación.',
          'Base jurídica: interés legítimo en garantizar la seguridad de la red y de la información (art. 6.1.f RGPD).',
          'Conservación: las sesiones caducan tras {sessionMinutes} minutos de inactividad y las caducadas se eliminan periódicamente; el registro de actividad de acceso se borra automáticamente a los {activityDays} días.',
        ],
      },
      {
        heading: 'Registros técnicos del servidor',
        paragraphs: [
          'Al visitar Chartiko, el servidor web registra la dirección IP, la fecha y hora, la página solicitada, la página de procedencia (referer) y el navegador, y la aplicación registra los errores técnicos que se producen.',
        ],
        items: [
          'Finalidad: hacer funcionar el servicio, diagnosticar errores y protegerlo frente a abusos.',
          'Base jurídica: interés legítimo (art. 6.1.f RGPD).',
          'Conservación: {logDays} días.',
        ],
      },
      {
        heading: 'Funciones de IA',
        paragraphs: [
          'Si eres usuario registrado y pides generar una ficha de empresa con IA, el servidor envía a DevExpert Inference (inference.devexpert.io), el proveedor externo de IA, la identidad del instrumento (ticker, nombre de la empresa, sector y bolsa), las cifras financieras públicas que Chartiko calcula con los informes de la empresa ante la SEC (EDGAR) y datos públicos de mercado de Chartiko (último cierre y variación, indicadores, Signals y patrones). La ficha generada se comparte con todos los usuarios y no contiene datos personales.',
          'Si eres usuario registrado y pides un pronóstico experimental, el servidor envía a TypeSafe AI (typesafe.ai), proveedor del modelo Jev, el histórico de precios diarios del instrumento, sus indicadores y sus Signals. El pronóstico se guarda por instrumento y fecha, no por usuario.',
          'En ninguno de los dos casos se envía ningún dato de tu cuenta (ni nombre, ni email, ni dirección IP, ni Watchlist, ni Screeners guardados): los proveedores solo reciben datos de mercado.',
          'Si eres usuario registrado y describes con tus palabras lo que buscas en el Screener, el servidor envía ese texto y el idioma de la página a DevExpert Inference para traducirlo a los filtros del Screener, sin ningún dato de tu cuenta. Chartiko no guarda ni registra tu texto; solo conserva durante 24 horas el resultado traducido (filtros, explicación y partes que no se han podido traducir) para reutilizarlo con peticiones idénticas. No incluyas datos personales en el texto.',
          'Para controlar el coste y evitar abusos registramos cada generación y cada búsqueda con IA que solicitas: tu identificador de cuenta, el instrumento (en las fichas), la fecha, el idioma, la longitud del texto de la búsqueda, el resultado y el número de tokens consumidos, nunca el texto enviado ni recibido.',
        ],
        items: [
          'Finalidad: generar la ficha que pides o traducir tu búsqueda a filtros, limitar el uso diario y prevenir abusos.',
          'Base jurídica: ejecución de las condiciones de uso para generar la ficha o traducir la búsqueda (art. 6.1.b RGPD) e interés legítimo en controlar costes y prevenir abusos para los registros (art. 6.1.f RGPD).',
          'Conservación: el registro de generaciones se borra automáticamente a los {aiCardLogDays} días y el de búsquedas con IA a los {aiScreenerLogDays} días.',
        ],
      },
      {
        heading: 'Copias de seguridad',
        paragraphs: [
          'Hacemos una copia diaria de la base de datos para poder recuperar el servicio ante fallos. Se guarda con acceso restringido.',
        ],
        items: [
          'Base jurídica: interés legítimo en la continuidad e integridad del servicio (art. 6.1.f RGPD).',
          'Conservación: {backupDays} días.',
        ],
      },
      {
        heading: 'Consultas y ejercicio de derechos',
        paragraphs: ['Si nos escribes, tratamos tu email y lo que nos cuentes para responderte.'],
        items: [
          'Base jurídica: cumplimiento de obligaciones legales al atender tus derechos (art. 6.1.c RGPD) e interés legítimo en responder otras consultas (art. 6.1.f RGPD).',
          'Conservación: el tiempo necesario para atender la solicitud y, después, durante los plazos legales en que pudieran exigirse responsabilidades.',
        ],
      },
      {
        heading: 'Lo que no hacemos',
        paragraphs: [
          'No usamos herramientas de analítica, publicidad ni redes sociales, no enviamos comunicaciones comerciales ni emails de alertas, no elaboramos perfiles ni tomamos decisiones automatizadas que te afecten. Los datos de mercado que muestra Chartiko se obtienen desde el servidor y no implican datos de los visitantes.',
        ],
      },
      {
        heading: 'Destinatarios y transferencias internacionales',
        paragraphs: [
          'No cedemos tus datos a terceros salvo obligación legal. El proveedor de alojamiento, OVH, trata los datos por cuenta de Chartiko como encargado del tratamiento.',
          'Si usas la búsqueda con IA del Screener, el texto que escribes se envía a DevExpert Inference, el proveedor de IA, solo para devolver los filtros (véase «Funciones de IA»); por eso te pedimos que no incluyas datos personales.',
          'El servidor está situado en Canadá (Beauharnois, Quebec). La Comisión Europea reconoce a Canadá un nivel de protección adecuado (Decisión 2002/2/CE), en el que se basa esta transferencia internacional.',
        ],
        accountEmail: [
          'Los emails de tu cuenta (como la confirmación de tu dirección) se envían a través de {emailProvider}, el proveedor de envío de correo, que los trata por cuenta de Chartiko como encargado del tratamiento. Ubicación del tratamiento: {emailProviderLocation}.',
        ],
        emailDigest: [
          'Si activas el resumen diario por email, tu dirección de correo y el contenido del email se envían a {emailProvider}, el proveedor de envío de correo, que los trata por cuenta de Chartiko como encargado del tratamiento. Ubicación del tratamiento: {emailProviderLocation}.',
        ],
      },
      {
        heading: 'Tus derechos',
        paragraphs: [
          'Si tienes cuenta, desde la página Cuenta de tu Portal puedes descargar una copia de tus datos en formato JSON (acceso y portabilidad), corregir tu nombre (rectificación) y eliminar la cuenta (supresión) tú mismo y al momento.', 'También puedes solicitar el acceso, la rectificación, la supresión, la oposición, la limitación del tratamiento y la portabilidad de tus datos escribiendo a {ownerEmail}; por ejemplo, para cambiar tu email. Te responderemos en el plazo de un mes.',
          'Si consideras que no hemos atendido bien tu solicitud, puedes presentar una reclamación ante la Agencia Española de Protección de Datos (www.aepd.es).',
        ],
      },
      {
        heading: 'Menores',
        paragraphs: ['Chartiko no está dirigido a menores de 14 años, que no deben registrarse.'],
      },
      {
        heading: 'Seguridad',
        paragraphs: [
          'Aplicamos medidas técnicas y organizativas proporcionadas: conexión cifrada (HTTPS), contraseñas almacenadas con hash, acceso de administración restringido, base de datos no accesible desde Internet y copias de seguridad privadas.',
        ],
      },
      {
        heading: 'Cookies',
        paragraphs: ['Sobre las cookies y el almacenamiento en tu navegador, consulta la {cookiesLink}.'],
      },
    ],
  },
  notice: {
    title: 'Aviso legal y condiciones de uso',
    intro:
      'Información sobre el titular de Chartiko (Ley 34/2002, de servicios de la sociedad de la información) y condiciones que se aplican al uso del servicio.',
    sections: [
      {
        heading: 'Titular',
        items: [
          'Titular: {ownerName}',
          'NIF: {ownerTaxId}',
          'Domicilio: {ownerAddress}',
          'Email de contacto: {ownerEmail}',
        ],
      },
      {
        heading: 'Objeto',
        paragraphs: [
          'Chartiko (www.chartiko.com) es una herramienta web de análisis técnico de acciones: permite filtrar un universo de acciones con criterios técnicos, consultar una lista ordenada de candidatos y explorar gráficos con indicadores y Signals. Los usuarios registrados pueden guardar Screeners y mantener una Watchlist. El uso del servicio no requiere pago.',
        ],
      },
      {
        heading: 'No es asesoramiento de inversión',
        paragraphs: [
          'La información de Chartiko (Screener, candidatos, Signals y gráficos) se genera automáticamente a partir de datos de mercado e indicadores técnicos y tiene carácter exclusivamente informativo y educativo. No constituye asesoramiento en materia de inversión, recomendación personalizada ni oferta de compra o venta de instrumentos financieros.',
          'Las cifras financieras las calcula Chartiko a partir de informes públicos de la SEC (EDGAR) y de nuestro último cierre, y pueden diferir de otras fuentes o no reflejar aún el último informe; la previsión redactada por IA puede contener errores y no está verificada.',
          'Los datos pueden contener errores o retrasos. Las rentabilidades pasadas no garantizan resultados futuros e invertir conlleva riesgo de pérdida. Consulta a un profesional autorizado antes de tomar decisiones de inversión.',
        ],
      },
      {
        heading: 'Condiciones de uso',
        items: [
          'Para registrarte debes tener al menos 14 años y facilitar datos veraces. Eres responsable de custodiar tu contraseña.',
          'Debes usar el servicio de forma lícita, sin intentar acceder a zonas o datos no autorizados, sobrecargarlo ni extraer sus datos de forma masiva o automatizada.',
          'Podemos suspender o cancelar las cuentas que incumplan estas condiciones.',
          'Puedes dejar de usar el servicio y eliminar tu cuenta en cualquier momento desde la página Cuenta de tu Portal o escribiendo a {ownerEmail}.',
          'Podemos modificar el servicio y estas condiciones; la fecha de actualización indica la última versión.',
        ],
        marketContext: [
          'Los datos macroeconómicos de la página Mercado proceden de la API FRED® del Banco de la Reserva Federal de St. Louis. Al usar Chartiko aceptas quedar vinculado por las {fredTermsLink}.',
        ],
      },
      {
        heading: 'Propiedad intelectual',
        paragraphs: [
          'El diseño, el software, la marca y los textos de Chartiko pertenecen a su titular o se usan con licencia. Los datos de mercado proceden de fuentes de terceros y pueden estar sujetos a sus propias condiciones; no se autoriza su reutilización comercial a través de Chartiko. Las cifras financieras de las empresas se calculan con informes públicos del sistema EDGAR de la Comisión de Bolsa y Valores de EE. UU. (SEC).',
        ],
        marketContext: [
          'Los datos macroeconómicos proceden de FRED® y de sus fuentes originales (Junta de la Reserva Federal, Departamento de Trabajo y Oficina del Censo de EE. UU., Banco Central Europeo y OCDE), citadas junto a cada dato. Chartiko usa la API FRED® pero no está respaldado ni certificado por el Banco de la Reserva Federal de St. Louis.',
        ],
      },
      {
        heading: 'Responsabilidad',
        paragraphs: [
          'Procuramos que el servicio esté disponible y que los datos sean correctos, pero pueden producirse interrupciones, errores o retrasos. No respondemos de las decisiones que tomes a partir de la información mostrada ni del contenido de sitios externos.',
        ],
      },
      {
        heading: 'Legislación aplicable',
        paragraphs: [
          'Estas condiciones se rigen por la legislación española. Si actúas como consumidor, conservas los derechos que te reconoce la normativa de consumo, incluido el de acudir a los tribunales de tu domicilio.',
          'El tratamiento de tus datos se explica en la {privacyLink}.',
        ],
      },
    ],
  },
  cookies: {
    title: 'Política de cookies',
    intro:
      'Las cookies y tecnologías similares, como el almacenamiento local del navegador, guardan información en tu dispositivo. Esta tabla enumera todas las que usa Chartiko.',
    rows: [
      {
        name: 'alphapulse-session',
        type: 'Cookie propia (HttpOnly, segura)',
        purpose: 'Mantiene tu sesión y la asocia a tus peticiones.',
        duration: '{sessionMinutes} minutos',
        category: 'Técnica, necesaria',
      },
      {
        name: 'XSRF-TOKEN',
        type: 'Cookie propia (segura)',
        purpose: 'Protege los formularios frente a peticiones falsificadas (CSRF).',
        duration: '{sessionMinutes} minutos',
        category: 'Técnica, necesaria',
      },
      {
        name: 'chartiko.locale',
        type: 'Almacenamiento local propio',
        purpose: 'Recuerda el idioma que eliges.',
        duration: 'Hasta que lo borres',
        category: 'Preferencia que eliges tú',
      },
      {
        name: 'chartiko.theme',
        type: 'Almacenamiento local propio',
        purpose: 'Recuerda el tema de color (claro u oscuro) que eliges.',
        duration: 'Hasta que lo borres',
        category: 'Preferencia que eliges tú',
      },
    ],
    sections: [
      {
        heading: 'Por qué no hay banner de cookies',
        paragraphs: [
          'Todas son estrictamente necesarias para prestar el servicio que solicitas o guardan una preferencia que tú eliges, por lo que no requieren consentimiento (art. 22.2 de la Ley 34/2002 y guía de la AEPD). Chartiko no usa cookies de analítica, de publicidad ni de terceros, y no carga recursos de otros dominios.',
        ],
      },
      {
        heading: 'Cómo eliminarlas',
        paragraphs: [
          'Puedes borrar o bloquear las cookies y el almacenamiento local desde la configuración de tu navegador. Si bloqueas las cookies técnicas no podrás iniciar sesión; si borras la preferencia de idioma o de tema, Chartiko usará el idioma o el tema de color de tu navegador.',
        ],
      },
      {
        heading: 'Cambios',
        paragraphs: [
          'Si incorporamos nuevas cookies o herramientas actualizaremos esta política y, si requieren consentimiento, te lo pediremos antes de usarlas.',
        ],
      },
    ],
  },
}
