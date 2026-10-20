<?php

namespace App\Services;

use App\Models\EntryImage;
use App\Models\KnowledgeEntry;

/**
 * Keeps entry_images rows in step with the images referenced by entry content.
 *
 * Uploading happens before an entry exists (the new-entry screen), so the link
 * cannot be made at upload time. Saving the entry is the moment the content is
 * known, and the moment an image removed from the editor can be cleaned up.
 */
class EntryImageService
{
    /**
     * The UUID is the stable part of the URL, so content survives a change to
     * APP_URL without losing track of its images.
     */
    private const UUID_PATTERN = '~entry-images/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})~i';

    /**
     * Associate every image the content references, and delete images that
     * used to belong to this entry but are no longer in it.
     */
    public function sync(KnowledgeEntry $entry): void
    {
        $uuids = $this->uuidsIn((string) $entry->content);

        EntryImage::query()
            ->where('workspace_id', $entry->workspace_id)
            ->whereIn('uuid', $uuids)
            ->get()
            ->each(function (EntryImage $image) use ($entry) {
                if ((int) $image->knowledge_entry_id !== (int) $entry->id) {
                    $image->forceFill([
                        'knowledge_entry_id' => $entry->id,
                    ])->save();
                }
            });

        $entry->entryImages()
            ->whereNotIn('uuid', $uuids)
            ->get()
            ->each->delete();
    }

    /**
     * @return list<string>
     */
    public function uuidsIn(string $html): array
    {
        preg_match_all(self::UUID_PATTERN, $html, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }
}
