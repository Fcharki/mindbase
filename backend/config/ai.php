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

    /*
    |--------------------------------------------------------------------------
    | Background Timeout
    |--------------------------------------------------------------------------
    |
    | Budget for a queued summary generation. The fast backend answers well
    | inside the synchronous timeout, but the instruction-tuned one takes
    | minutes on CPU, so a regeneration that is allowed to pick it cannot share
    | the shorter budget. DB_QUEUE_RETRY_AFTER must stay above this.
    |
    */

    'background_timeout' => (int) env('AI_SERVICE_BACKGROUND_TIMEOUT', 600),

    /*
    |--------------------------------------------------------------------------
    | Backend Cost
    |--------------------------------------------------------------------------
    |
    | What each summarization backend actually costs to run, in seconds, measured
    | on the 4-core CPU container this project ships on: t5 answers in seconds,
    | qwen loads in about 535s and then decodes at roughly 0.1 tokens/second,
    | which is about 33 minutes for a 200 token answer.
    |
    | These numbers exist so a request naming a backend that cannot finish is
    | refused with an explanation, rather than accepted, left to occupy a worker
    | for the full budget, and then failing as an opaque transport error. The
    | second summary used to do exactly that.
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
    | Off by default, which is what turns the cost above into a refusal. Set it
    | to queue a backend that exceeds the budget anyway, on hardware where the
    | estimate is wrong or the work is genuinely worth waiting for. The job will
    | still be killed at its own timeout if the estimate was optimistic.
    |
    */

    'allow_slow_backends' => (bool) env('AI_ALLOW_SLOW_BACKENDS', false),

    /*
    |--------------------------------------------------------------------------
    | Summary Backends
    |--------------------------------------------------------------------------
    |
    | The models the AI service can summarize with, which is what a caller may
    | name when asking for one entry to be regenerated. Kept here rather than
    | in the request so there is one list to extend when a model is added.
    |
    */

    'backends' => ['t5', 'qwen'],

    /*
    |--------------------------------------------------------------------------
    | Embedding Dimension
    |--------------------------------------------------------------------------
    |
    | Width of the vectors the embedding model returns, and therefore of the
    | vector column. Changing the model means changing this, which means
    | rebuilding the column and every chunk vector stored in it.
    |
    */

    'embedding_dimension' => (int) env('AI_EMBEDDING_DIMENSION', 1024),

];
