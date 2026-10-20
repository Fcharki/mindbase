<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    /**
     * Maximum accepted upload size, in kilobytes. Kept in step with
     * upload_max_filesize in backend/docker/uploads.ini, which is itself below
     * the client_max_body_size ceiling in docker/nginx/nginx.conf.
     */
    public const MAX_UPLOAD_KB = 20480;

    /**
     * Allowed upload types, validated against the reported extension.
     *
     * Content sniffing ("mimes:") is deliberately not used: the MIME guesser
     * reports text/plain for .md and .csv, and cannot identify every .docx,
     * so it rejects legitimate uploads. The extensions rule also blocks PHP
     * uploads outright. Downloads are always streamed as an attachment with a
     * stored filename, so the browser never renders the binary inline.
     */
    public const ALLOWED_EXTENSIONS = [
        'pdf',
        'doc',
        'docx',
        'xls',
        'xlsx',
        'ppt',
        'pptx',
        'txt',
        'md',
        'csv',
        // Images are attachments too: the panel can preview them inline and
        // downloading them works the same as any other document.
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
    ];

    /**
     * Uploading is gated by DocumentPolicy::create() in the controller, which
     * runs after the 404 that nested routes return for entries in another
     * workspace.
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
            ],
        ];
    }
}
