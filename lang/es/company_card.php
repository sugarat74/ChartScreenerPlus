<?php

// Mensajes de la API de la ficha de empresa IA (ai-company-card). Las claves
// son los `code` estables del error; el estado HTTP no depende del idioma.
return [
    'provider_not_configured' => 'Las fichas de empresa con IA aún no están disponibles.',
    'provider_unavailable' => 'El proveedor de IA no está disponible. Inténtalo más tarde.',
    'invalid_provider_response' => 'El proveedor de IA devolvió una ficha no válida. No se ha guardado nada; inténtalo más tarde.',
    'busy' => 'Esta ficha ya se está generando. Inténtalo de nuevo en un momento.',
    'daily_limit_reached' => 'Se ha alcanzado el límite diario de fichas con IA. Inténtalo mañana.',
    'user_daily_limit' => 'Has alcanzado tu límite diario de fichas con IA. Inténtalo mañana.',
];
