<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Service
    |--------------------------------------------------------------------------
    |
    | Base URL of the FastAPI service that runs the local models. Requests are
    | proxied through it rather than made from the browser.
    |
    */

    'service_url' => env('AI_SERVICE_URL', 'http://ai-service:8002'),

    'timeout' => (int) env('AI_SERVICE_TIMEOUT', 120),

    /*
    |--------------------------------------------------------------------------
    | Background Timeout
    |--------------------------------------------------------------------------
    |
    | Budget for a queued summary generation. The instruction-tuned backend takes
    | minutes on CPU, so a regeneration allowed to pick it cannot share the
    | synchronous budget. DB_QUEUE_RETRY_AFTER must stay above this.
    |
    */

    'background_timeout' => (int) env('AI_SERVICE_BACKGROUND_TIMEOUT', 600),

    /*
    |--------------------------------------------------------------------------
    | Answer Timeout
    |--------------------------------------------------------------------------
    |
    | Budget for one queued assistant answer. Separate from the background
    | timeout above, because raising that would also let a summary pick the
    | instruction-tuned backend, which is a different decision.
    |
    | Must stay below the job's own $timeout and DB_QUEUE_RETRY_AFTER.
    |
    */

    'answer_timeout' => (int) env('AI_SERVICE_ANSWER_TIMEOUT', 2400),

    /*
    |--------------------------------------------------------------------------
    | Backend Cost
    |--------------------------------------------------------------------------
    |
    | What each summarization backend costs to run in seconds, measured on the
    | 4-core container: t5 answers in seconds, qwen loads in about 535s and
    | decodes at roughly 0.1 tokens/second.
    |
    | These exist so a request naming a backend that cannot finish is refused
    | with an explanation, rather than occupying a worker for the full budget
    | and then failing as an opaque transport error.
    |
    */

    'backend_seconds' => [
        't5' => 15,
        'qwen' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Allow Slow Backends
    |--------------------------------------------------------------------------
    |
    | Off by default, which is what turns the cost above into a refusal. Set it to
    | queue an over-budget backend anyway, on hardware where the estimate is
    | wrong. The job still dies at its own timeout if the estimate was low.
    |
    */

    'allow_slow_backends' => (bool) env('AI_ALLOW_SLOW_BACKENDS', false),

    /*
    |--------------------------------------------------------------------------
    | Summary Backends
    |--------------------------------------------------------------------------
    |
    | The models the AI service can summarize with, and so what a caller may name
    | when asking for a regeneration.
    |
    */

    'backends' => ['t5', 'qwen'],

    /*
    |--------------------------------------------------------------------------
    | Embedding Dimension
    |--------------------------------------------------------------------------
    |
    | Width of the vectors the embedding model returns, and so of the vector
    | column. Changing the model means changing this, which means rebuilding
    | the column and every vector in it.
    |
    */

    'embedding_dimension' => (int) env('AI_EMBEDDING_DIMENSION', 1024),

    /*
    |--------------------------------------------------------------------------
    | Embedding Cache TTL
    |--------------------------------------------------------------------------
    |
    | How long a search question's vector is kept, in seconds. The model is the
    | slowest part of a search -- about a second warm, minutes cold -- and a
    | debounced search box re-embeds the same question on almost every keystroke
    | that survives the pause, so the vector is cached by its text.
    |
    | An embedding is deterministic for a given model, so this only bounds how
    | large the cache table may grow, not correctness. The dimension is part of
    | the cache key, so changing the model width invalidates by construction;
    | clear the cache if the model changes at the same width.
    |
    */

    'embedding_cache_ttl' => (int) env('AI_EMBEDDING_CACHE_TTL', 60 * 60 * 24 * 30),

];
