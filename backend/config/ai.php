<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Service
    |--------------------------------------------------------------------------
    |
    | Base URL of the FastAPI service that runs the local summarization
    | model. Used by the summarization feature to proxy requests.
    |
    */

    'service_url' => env('AI_SERVICE_URL', 'http://ai-service:8002'),

    'timeout' => (int) env('AI_SERVICE_TIMEOUT', 120),

];