<?php

/*
|--------------------------------------------------------------------------
| Mensajes de la API de Chartiko
|--------------------------------------------------------------------------
|
| Mensajes visibles que devuelve la API. Mismas claves en todos los idiomas
| (lo comprueba LocalizationTest). Los códigos de estado, la forma de las
| respuestas, las claves de `errors` y los códigos de Signal no dependen de
| estos textos.
|
*/

return [
    'instrument_not_found' => 'Instrumento no encontrado.',
    'instrument_unknown' => 'Instrumento desconocido.',
    'not_in_watchlist' => 'El instrumento no está en tu watchlist.',
    'screener_not_found' => 'Screener no encontrado.',
    'universe_not_found' => 'Universo no encontrado.',
    'admin_required' => 'Se requiere acceso de administrador.',
    'stateful_session_required' => 'Se requiere una sesión con estado.',
    'user_not_found' => 'Usuario no encontrado.',
    'session_not_found' => 'Sesión no encontrada o ya finalizada.',
    'session_is_current' => 'No puedes cerrar la sesión que estás usando ahora mismo.',

    // Errores de API generados por el framework (App\Http\LocalizedFrameworkMessages).
    'http' => [
        'unauthenticated' => 'No has iniciado sesión.',
        'forbidden' => 'No tienes permiso para realizar esta acción.',
        'not_found' => 'No se encontró el recurso solicitado.',
        'method_not_allowed' => 'Este método no está permitido para el recurso solicitado.',
        'session_expired' => 'Tu sesión ha caducado. Recarga la página e inténtalo de nuevo.',
        'too_many_attempts' => 'Demasiados intentos. Espera un momento e inténtalo de nuevo.',
        'server_error' => 'Error del servidor.',
        'service_unavailable' => 'Servicio no disponible temporalmente. Inténtalo más tarde.',
    ],

    'ingestion' => [
        'universe_unknown' => 'No se encontró el universo [:slug].',
        'run_not_finished' => 'Solo se puede reintentar una ejecución terminada.',
        'run_without_failures' => 'Esta ejecución no tiene instrumentos fallidos que reintentar.',
    ],

    'screener' => [
        'signal_unknown_types' => 'El parámetro signal solo puede contener tipos de señal conocidos.',
        'signal_invalid' => 'El tipo de señal seleccionado no es válido.',
        'pattern_invalid' => 'El tipo de patrón seleccionado no es válido.',
        'pattern_status_invalid' => 'El estado de patrón debe ser any, forming o confirmed.',
        'parameter_number' => 'El parámetro :parameter debe ser un número.',
        'parameter_boolean' => 'El parámetro :parameter debe ser booleano (1/true/on/yes o 0/false/off/no).',
        'ma_cross_invalid' => 'El parámetro ma_cross debe ser bullish o bearish.',
        'sort_invalid' => 'El parámetro sort debe ser uno de: :values.',
    ],
];
