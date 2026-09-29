<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Engine base URL
    |--------------------------------------------------------------------------
    |
    | The Python engine is an internal HTTP service. Laravel calls it for
    | scraping/parsing (the engine never writes to the database).
    |
    */

    'url' => env('ENGINE_URL', 'http://127.0.0.1:8090'),

];
