<?php

// Account emails (auth-email-verification; auth-password-reset adds `reset`).
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
];
