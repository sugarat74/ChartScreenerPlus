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
    'test' => [
        'subject' => 'Email de prueba de Chartiko',
        'heading' => 'Email de prueba de Chartiko',
        'intro' => 'Este email comprueba la configuración de envío de Chartiko. No tienes que hacer nada.',
        'site' => 'Web de Chartiko:',
        'ignore' => 'Si no esperabas este email, ignóralo: no se ha creado ninguna cuenta con esta dirección.',
        'why' => 'Recibes este email porque el administrador de chartiko.com ha enviado una prueba a esta dirección.',
    ],
];
