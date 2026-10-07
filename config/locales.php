<?php

/*
|--------------------------------------------------------------------------
| Chartiko locales
|--------------------------------------------------------------------------
|
| Languages the API answers in. Keep this list in sync with the SPA registry
| (frontend/src/i18n/locales.ts). To add a language, add its code here, add
| lang/<code>/{validation,auth,passwords,pagination,messages}.php with the
| same keys as lang/en, and register it in the SPA.
|
*/

return [
    'supported' => ['es', 'en'],

    // Used when Accept-Language is absent or names no supported language.
    'default' => 'es',
];
