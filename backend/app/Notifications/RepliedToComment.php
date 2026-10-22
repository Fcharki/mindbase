<?php

namespace App\Notifications;

use App\Models\Reply;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class RepliedToComment extends Notification
{
    use Queueable;

    /**
     * @param  Reply  $reply  The reply just posted.
     * @param  User  $actor  Who wrote it, resolved by the caller.
     */
    public function __construct(
        private readonly Reply $reply,
        private readonly User $actor,
    ) {}

    /**
     * Channels match CommentedOnEntry, and for the same reason.
     *
     * This was generated with a broadcast channel, which cannot work here: the
     * project runs no broadcasting driver (BROADCAST_CONNECTION=log in
     * development, null in tests, and there is no config/broadcasting.php), so
     * a broadcast notification would be written to a log and delivered to
     * nobody. The reader of these is the notification list, which reads rows.
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
        $comment = $this->reply->comment;

        return [
            'kind' => 'reply',
            'actor_username' => $this->actor->username,
            'entry_title' => $comment?->knowledgeEntry?->title,
            // The comment body, not the reply's: it is the thread being
            // continued, and it is what the reader needs to recognise the
            // conversation they are being pulled into.
            'excerpt' => Str::limit($this->reply->body, 140),
            'workspace_id' => $comment?->knowledgeEntry?->workspace_id,
            'entry_id' => $comment?->knowledge_entry_id,
            'comment_id' => $this->reply->comment_id,
            'reply_id' => $this->reply->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $comment = $this->reply->comment;
        $entry = $comment?->knowledgeEntry;

        $message = (new MailMessage)
            ->subject("{$this->actor->username} replied to your comment")
            ->line("{$this->actor->username} replied to your comment on \"{$entry?->title}\":")
            ->line(Str::limit($this->reply->body, 400));

        if ($entry !== null) {
            $message->action(
                'Open the reply',
                $this->replyUrl(
                    (int) $entry->workspace_id,
                    (int) $entry->id,
                    (int) $this->reply->comment_id,
                    (int) $this->reply->id,
                ),
            );
        }

        return $message;
    }

    private function replyUrl(
        int $workspaceId,
        int $entryId,
        int $commentId,
        int $replyId,
    ): string {
        return rtrim((string) config('app.frontend_url'), '/')
            ."/workspaces/{$workspaceId}/entries/{$entryId}"
            ."?comment={$commentId}&reply={$replyId}";
    }
}
