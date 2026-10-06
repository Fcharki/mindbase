<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class CommentedOnEntry extends Notification
{
    use Queueable;

    /**
     * @param  Comment  $comment  The comment just posted.
     * @param  User  $actor  Who wrote it, resolved by the caller so a deleted
     *                       account cannot produce a blank line.
     */
    public function __construct(
        private readonly Comment $comment,
        private readonly User $actor,
    ) {}

    /**
     * Stored first, mailed second.
     *
     * The database channel is what the in-app list reads, so it is the channel
     * that matters; mail is a courtesy copy. Both run inline rather than on the
     * queue, because a queued delivery would mean the reader posts a comment
     * and their own notification list has not caught up yet. Add ShouldQueue
     * here if mail latency ever becomes the problem worth solving.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'comment',
            'actor_name' => $this->actor->name,
            'entry_title' => $this->comment->knowledgeEntry?->title,
            'excerpt' => Str::limit($this->comment->body, 140),
            // The ids the client needs to navigate to this exact comment, and to
            // re-check that the reader may still see it. workspace_id is carried
            // because the comment knows its entry but not its workspace without
            // another hop, and the link is built from the workspace.
            'workspace_id' => $this->comment->knowledgeEntry?->workspace_id,
            'entry_id' => $this->comment->knowledge_entry_id,
            'comment_id' => $this->comment->id,
            'reply_id' => null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $entry = $this->comment->knowledgeEntry;

        $message = (new MailMessage)
            ->subject("{$this->actor->name} commented on \"{$entry?->title}\"")
            ->line("{$this->actor->name} commented on \"{$entry?->title}\":")
            ->line(Str::limit($this->comment->body, 400));

        if ($entry !== null) {
            $message->action(
                'Open the discussion',
                $this->entryUrl($entry->workspace_id, $entry->id, $this->comment->id),
            );
        }

        return $message;
    }

    /**
     * Built against the configured frontend rather than url() so the link works
     * when the API and the app sit on different hosts, which is the case
     * throughout docker-compose.
     */
    private function entryUrl(int $workspaceId, int $entryId, int $commentId): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            ."/workspaces/{$workspaceId}/entries/{$entryId}"
            ."?comment={$commentId}";
    }
}
