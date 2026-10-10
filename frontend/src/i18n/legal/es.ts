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
          'NIF: {ownerTaxId}',
          'Domicilio: {ownerAddress}',
          'Contacto y protección de datos: {ownerEmail}',
        ],
      },
      {
        heading: 'Cuenta de usuario',
        paragraphs: [
          'Si creas una cuenta tratamos tu nombre, tu email y tu contraseña, que se guarda transformada mediante una función hash y nunca en claro. También guardamos los Screeners y la Watchlist que decides conservar.',
        ],
        items: [
          'Finalidad: crear y mantener tu cuenta y ofrecerte las funciones de usuario registrado.',
          'Base jurídica: ejecución del contrato de uso del servicio que aceptas al registrarte (art. 6.1.b RGPD).',
          'Conservación: mientras mantengas la cuenta. Si pides su supresión la borramos; las copias de seguridad pueden conservarla hasta {backupDays} días más y el registro de actividad de acceso conserva el email hasta que se borra, a los {activityDays} días.',
        ],
      },
      {
        heading: 'Sesiones y seguridad del acceso',
        paragraphs: [
          'Para mantener tu sesión y proteger las cuentas registramos la dirección IP, el navegador (agente de usuario) y la hora de la última actividad de cada sesión, así como los inicios de sesión, los intentos fallidos (con el email introducido, que puede no corresponder a ninguna cuenta), los cierres de sesión y las sesiones cerradas por la administración. Los visitantes sin cuenta también tienen una sesión técnica con la dirección IP, el navegador y la última actividad.',
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
          'No usamos herramientas de analítica, publicidad ni redes sociales, no enviamos comunicaciones comerciales, no elaboramos perfiles ni tomamos decisiones automatizadas que te afecten. Los datos de mercado que muestra Chartiko se obtienen desde el servidor y no implican datos de los visitantes.',
        ],
      },
      {
        heading: 'Destinatarios y transferencias internacionales',
        paragraphs: [
          'No cedemos tus datos a terceros salvo obligación legal. El proveedor de alojamiento, OVH, trata los datos por cuenta de Chartiko como encargado del tratamiento.',
          'El servidor está situado en Canadá (Beauharnois, Quebec). La Comisión Europea reconoce a Canadá un nivel de protección adecuado (Decisión 2002/2/CE), en el que se basa esta transferencia internacional.',
        ],
      },
      {
        heading: 'Tus derechos',
        paragraphs: [
          'Puedes solicitar el acceso, la rectificación, la supresión, la oposición, la limitación del tratamiento y la portabilidad de tus datos escribiendo a {ownerEmail}. Te responderemos en el plazo de un mes.',
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
          'Chartiko (www.chartiko.com) es una herramienta web de análisis técnico de acciones: permite filtrar un universo de acciones con criterios técnicos, consultar una lista ordenada de Candidates y explorar gráficos con indicadores y Signals. Los usuarios registrados pueden guardar Screeners y mantener una Watchlist. El uso del servicio no requiere pago.',
        ],
      },
      {
        heading: 'No es asesoramiento de inversión',
        paragraphs: [
          'La información de Chartiko (Screener, Candidates, Signals y gráficos) se genera automáticamente a partir de datos de mercado e indicadores técnicos y tiene carácter exclusivamente informativo y educativo. No constituye asesoramiento en materia de inversión, recomendación personalizada ni oferta de compra o venta de instrumentos financieros.',
          'Los datos pueden contener errores o retrasos. Las rentabilidades pasadas no garantizan resultados futuros e invertir conlleva riesgo de pérdida. Consulta a un profesional autorizado antes de tomar decisiones de inversión.',
        ],
      },
      {
        heading: 'Condiciones de uso',
        items: [
          'Para registrarte debes tener al menos 14 años y facilitar datos veraces. Eres responsable de custodiar tu contraseña.',
          'Debes usar el servicio de forma lícita, sin intentar acceder a zonas o datos no autorizados, sobrecargarlo ni extraer sus datos de forma masiva o automatizada.',
          'Podemos suspender o cancelar las cuentas que incumplan estas condiciones.',
          'Puedes dejar de usar el servicio y pedir la supresión de tu cuenta en cualquier momento escribiendo a {ownerEmail}.',
          'Podemos modificar el servicio y estas condiciones; la fecha de actualización indica la última versión.',
        ],
      },
      {
        heading: 'Propiedad intelectual',
        paragraphs: [
          'El diseño, el software, la marca y los textos de Chartiko pertenecen a su titular o se usan con licencia. Los datos de mercado proceden de fuentes de terceros y pueden estar sujetos a sus propias condiciones; no se autoriza su reutilización comercial a través de Chartiko.',
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
          'Puedes borrar o bloquear las cookies y el almacenamiento local desde la configuración de tu navegador. Si bloqueas las cookies técnicas no podrás iniciar sesión; si borras la preferencia de idioma, Chartiko usará el idioma de tu navegador.',
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
