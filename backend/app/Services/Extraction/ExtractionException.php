<?php

namespace App\Services\Extraction;

use RuntimeException;

/**
 * A file could not be read into text.
 *
 * The message is written to be shown to a person, because it ends up in the
 * document's extraction_error column and on the API.
 */
class ExtractionException extends RuntimeException {}
