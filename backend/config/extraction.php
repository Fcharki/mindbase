<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extracted text cap
    |--------------------------------------------------------------------------
    |
    | Upper bound on the characters kept from a single attachment. Without it a
    | long PDF would be embedded in full and, because retrieval is per workspace,
    | could dominate every search in that workspace. Zero disables the cap.
    |
    */

    'max_characters' => (int) env('EXTRACTION_MAX_CHARACTERS', 200000),

    /*
    |--------------------------------------------------------------------------
    | Archive limits
    |--------------------------------------------------------------------------
    |
    | An office file is a zip archive, and a hostile one can be a zip bomb: a
    | small download that inflates to something far larger. These bounds are
    | checked against the archive's own index, before any entry is read, so a
    | disproportionate file is rejected while it is still cheap to abandon.
    |
    | - max_file_bytes: the archive's size on disk.
    | - max_entries: how many parts it may contain.
    | - max_uncompressed_bytes: the total uncompressed size of all parts.
    | - max_part_bytes: the uncompressed size of any single part.
    |
    | Zero disables a given limit.
    |
    */

    'limits' => [
        'max_file_bytes' => (int) env('EXTRACTION_MAX_FILE_BYTES', 50 * 1024 * 1024),
        'max_entries' => (int) env('EXTRACTION_MAX_ENTRIES', 5000),
        'max_uncompressed_bytes' => (int) env('EXTRACTION_MAX_UNCOMPRESSED_BYTES', 200 * 1024 * 1024),
        'max_part_bytes' => (int) env('EXTRACTION_MAX_PART_BYTES', 50 * 1024 * 1024),

        /*
        |----------------------------------------------------------------------
        | Processing limits
        |----------------------------------------------------------------------
        |
        | The archive limits bound the file's size, but a legitimately-sized
        | spreadsheet can still hold a million rows. These bound how much the
        | readers walk before they stop: the budget is spent as structures are
        | read, and the reader returns what it has, with a warning, rather than
        | parsing the whole file only to be capped afterwards.
        |
        | - max_paragraphs: docx paragraphs and tables.
        | - max_slides: pptx slides.
        | - max_rows: xlsx rows, across all worksheets.
        | - max_cells: xlsx cells, across all worksheets.
        |
        | Zero disables a given limit.
        |
        */

        'max_paragraphs' => (int) env('EXTRACTION_MAX_PARAGRAPHS', 200000),
        'max_slides' => (int) env('EXTRACTION_MAX_SLIDES', 5000),
        'max_rows' => (int) env('EXTRACTION_MAX_ROWS', 200000),
        'max_cells' => (int) env('EXTRACTION_MAX_CELLS', 2000000),
    ],

];
