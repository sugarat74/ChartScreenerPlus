<?php

// Mensajes de la API del Screener en lenguaje natural
// (ai-screener-natural-language). Las claves son los `code` estables que
// acompañan al error; los códigos de estado no dependen del idioma.
return [
    'provider_not_configured' => 'La búsqueda descrita con tus palabras aún no está disponible.',
    'provider_unavailable' => 'El proveedor de IA no está disponible. Inténtalo más tarde o usa los filtros.',
    'invalid_provider_response' => 'La IA no ha podido convertir tu petición en filtros. No se ha cambiado nada; reformúlala o usa los filtros.',
    'daily_limit_reached' => 'Se ha alcanzado el límite diario de búsquedas con IA. Inténtalo mañana o usa los filtros.',
    'user_daily_limit' => 'Has alcanzado tu límite diario de búsquedas con IA. Inténtalo mañana o usa los filtros.',
    // Se añade a `unsupported` cuando la respuesta usa los filtros sectoriales
    // y la cuenta no tiene acceso al contexto de mercado (market-context-patterns, C7).
    'market_context_unavailable' => 'Filtros de contexto sectorial (no disponibles para tu cuenta)',
    // Se añade cuando la respuesta usa los filtros sectoriales y el universo elegido no es de EE. UU.
    'sector_context_unavailable_for_universe' => 'Filtros de contexto sectorial (no disponibles para este mercado)',
];
