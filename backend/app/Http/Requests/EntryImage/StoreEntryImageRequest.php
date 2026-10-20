<?php

namespace App\Http\Requests\EntryImage;

use Illuminate\Foundation\Http\FormRequest;

class StoreEntryImageRequest extends FormRequest
{
    /**
     * Maximum accepted image size, in kilobytes. Kept well below the document
     * ceiling so inline images cannot be used to fill the content column.
     */
    public const MAX_UPLOAD_KB = 10240;

    /**
     * Only raster formats the browser can render. SVG is deliberately absent:
     * it can carry script, and these images are served inline.
     *
     * @var list<string>
     */
    public const ALLOWED_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
    ];

    /**
     * Uploading is gated by KnowledgeEntryPolicy::create() in the controller,
     * which is the same capability that allows writing entry content.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:'.self::MAX_UPLOAD_KB,
                'extensions:'.implode(',', self::ALLOWED_EXTENSIONS),
                // Extensions alone let a PHP script renamed to .png through.
                // The guessed MIME is checked against the bytes as well.
                'mimetypes:image/jpeg,image/png,image/gif,image/webp',
            ],
        ];
    }
}
