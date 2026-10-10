# Borrador: tratamiento «Actividad de acceso y sesiones»

> Borrador técnico redactado con el skill `web-legal-compliance-eu` a partir del tratamiento real
> implementado en `admin-users-sessions`. **No es asesoramiento jurídico** y debe revisarlo un
> profesional. Los datos del responsable están **pendientes** y no deben publicarse con
> marcadores: bloquean la publicación del aviso de privacidad.

## Responsable del tratamiento

- Nombre o razón social: **PENDIENTE (lo aporta el titular)**
- NIF/CIF: **PENDIENTE**
- Domicilio: **PENDIENTE**
- Email de contacto para privacidad: **PENDIENTE**

## Finalidad

Proteger las cuentas y el servicio de Chartiko: detectar accesos indebidos o intentos repetidos de
inicio de sesión, permitir que un administrador cierre sesiones abiertas y conocer el uso básico de
las cuentas (alta, último acceso, última actividad). No se usa para publicidad, perfiles comerciales
ni seguimiento de la navegación.

## Base jurídica

Interés legítimo del responsable en garantizar la seguridad de la red y de la información
(art. 6.1.f RGPD; considerando 49). Para los datos de la cuenta ya existentes, ejecución del contrato
de uso del servicio (art. 6.1.b RGPD). *Pendiente de confirmar por el profesional que revise el texto.*

## Datos tratados

- Inicios de sesión correctos, intentos fallidos, cierres de sesión y sesiones cerradas por un
  administrador: fecha y hora, cuenta afectada, email introducido (en intentos fallidos puede no
  corresponder a ninguna cuenta), dirección IP y agente de usuario del navegador.
- Sesiones abiertas: dirección IP, agente de usuario y última actividad (almacén de sesiones).
- Nunca se guardan contraseñas ni el contenido de los intentos fallidos más allá del email.

## Conservación

- Registro de actividad de acceso (`login_events`): **90 días**. Un proceso diario
  (`model:prune`, 03:15) borra los registros más antiguos. Configurable con
  `ADMIN_ACTIVITY_RETENTION_DAYS`; cualquier cambio debe reflejarse en el aviso de privacidad.
- Copias de seguridad: el volcado diario de PostgreSQL (`chartiko-backup-postgresql`, privado en el
  VPS) incluye `login_events` y se conserva 14 días, de modo que un registro puede persistir en las
  copias hasta unos 14 días después de borrarse de la base de datos (como máximo ~104 días en total).
  Debe indicarse en el aviso de privacidad o ajustarse la rotación de copias.
- Sesiones: mientras están activas; caducan tras el tiempo de inactividad configurado
  (`SESSION_LIFETIME`, 120 minutos) y el recolector de sesiones las elimina.

## Destinatarios y encargados

- Acceso limitado a cuentas con rol de administrador.
- Encargado de tratamiento: proveedor de alojamiento del VPS (OVH). *Pendiente: verificar contrato
  de encargo (DPA).*
- No hay cesiones a terceros.
- Transferencia internacional: el VPS está en Canadá (OVH Beauharnois, Quebec; comprobado el
  2026-10-10). Se apoya en la decisión de adecuación de la UE para Canadá (2002/2/CE); confirmar en
  la revisión jurídica junto con el DPA de OVH.

## Derechos

Acceso, rectificación, supresión, oposición, limitación y portabilidad mediante el email de contacto
(**pendiente**) y reclamación ante la AEPD (www.aepd.es).

## Medidas técnicas

- Endpoints solo para administradores (`auth:sanctum` + `admin`; 401/403 en otro caso).
- Los identificadores de sesión nunca salen del servidor (referencia opaca HMAC).
- Las acciones de cierre de sesión quedan registradas con el administrador que las ejecuta.
- PostgreSQL y PgBouncer solo escuchan en `127.0.0.1`; copias de seguridad privadas.

## Pendiente antes de publicar

1. Datos identificativos del responsable.
2. La política de privacidad de la web (`/privacidad`, feature `legal-compliance-eu`) ya incluye este
   tratamiento; se publica automáticamente al configurar los datos del punto 1 en producción
   (`docs/specs/legal-compliance-eu.md`).
3. Revisión por un profesional de la base jurídica y del texto final.
