<?php

// Account emails (auth-email-verification; auth-password-reset adds `reset`;
// alerts-email-golive adds `test`, the operator test email of `mail:test`).
// No user-supplied text is ever interpolated into them.

return [
    'layout' => [
        'why' => 'Recibes este email porque se ha creado una cuenta en chartiko.com con esta dirección.',
        'privacy' => 'Política de privacidad',
        'ignore' => 'Si no has creado una cuenta en Chartiko, ignora este email: sin confirmarla, la cuenta no puede usar ninguna función.',
    ],
    'verify' => [
        'subject' => 'Confirma tu email en Chartiko',
        'heading' => 'Confirma tu dirección de email',
        'intro' => 'Para activar tu cuenta de Chartiko, confirma que esta dirección es tuya.',
        'button' => 'Confirmar mi email',
        'fallback' => 'Si el botón no funciona, copia este enlace en tu navegador:',
        'expires' => 'El enlace caduca en 24 horas. Si caduca, inicia sesión y pide otro.',
    ],
    'reset' => [
        'subject' => 'Restablece tu contraseña de Chartiko',
        'heading' => 'Restablece tu contraseña',
        'intro' => 'Hemos recibido una solicitud para cambiar la contraseña de tu cuenta de Chartiko.',
        'button' => 'Cambiar contraseña',
        'fallback' => 'Si el botón no funciona, copia este enlace en tu navegador:',
        'expires' => 'El enlace caduca en 60 minutos y solo puede usarse una vez.',
        'ignore' => 'Si no has solicitado el cambio, ignora este email. Tu contraseña no cambiará.',
        'why' => 'Recibes este email porque se ha pedido recuperar la contraseña de una cuenta de chartiko.com con esta dirección.',
    ],
    'test' => [
        'subject' => 'Email de prueba de Chartiko',
        'heading' => 'Email de prueba de Chartiko',
        'intro' => 'Este email comprueba la configuración de envío de Chartiko. No tienes que hacer nada.',
        'site' => 'Web de Chartiko:',
        'ignore' => 'Si no esperabas este email, ignóralo: no se ha creado ninguna cuenta con esta dirección.',
        'why' => 'Recibes este email porque el administrador de chartiko.com ha enviado una prueba a esta dirección.',
    ],
];
