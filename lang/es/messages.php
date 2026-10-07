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

    'ingestion' => [
        'universe_unknown' => 'No se encontró el universo [:slug].',
        'run_not_finished' => 'Solo se puede reintentar una ejecución terminada.',
        'run_without_failures' => 'Esta ejecución no tiene instrumentos fallidos que reintentar.',
    ],

    'screener' => [
        'signal_unknown_types' => 'El parámetro signal solo puede contener tipos de señal conocidos.',
        'signal_invalid' => 'El tipo de señal seleccionado no es válido.',
        'parameter_number' => 'El parámetro :parameter debe ser un número.',
        'parameter_boolean' => 'El parámetro :parameter debe ser booleano (1/true/on/yes o 0/false/off/no).',
        'ma_cross_invalid' => 'El parámetro ma_cross debe ser bullish o bearish.',
        'sort_invalid' => 'El parámetro sort debe ser uno de: :values.',
    ],
];
