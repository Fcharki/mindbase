<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\KnowledgeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\CommentedOnEntry;
use App\Notifications\RepliedToComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The in-app notification list, and the payloads that let it link to the exact
 * post that caused it.
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        $owner = User::factory()->create();
        $workspace = Workspace::create([
            'name' => 'Acme',
            'owner_id' => $owner->id,
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }

    private function entry(Workspace $workspace, User $creator): KnowledgeEntry
    {
        return KnowledgeEntry::create([
            'workspace_id' => $workspace->id,
            'created_by' => $creator->id,
            'title' => 'Deployment runbook',
            'content' => 'Body',
        ]);
    }

    private function attach(Workspace $workspace, User $user, string $role): User
    {
        $user->workspaces()->attach($workspace->id, ['role' => $role]);

        return $user;
    }

    private function commentsUrl(Workspace $workspace, KnowledgeEntry $entry): string
    {
        return "/api/v1/workspaces/{$workspace->id}/entries/{$entry->id}/comments";
    }

    /**
     * Comment through the API rather than through the model.
     *
     * Only the request path sends notifications, so a test that builds a
     * comment with $entry->comments()->create() is testing a comment nobody was
     * told about.
     */
    private function postComment(
        User $actor,
        Workspace $workspace,
        KnowledgeEntry $entry,
        string $body
    ): Comment {
        $this->actingAs($actor)
            ->postJson($this->commentsUrl($workspace, $entry), ['body' => $body])
            ->assertCreated();

        return Comment::where('user_id', $actor->id)
            ->where('knowledge_entry_id', $entry->id)
            ->latest('id')
            ->sole();
    }

    public function test_commenting_notifies_the_entry_author_and_carries_the_ids_needed_to_link_to_it(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $this->actingAs($member)
            ->postJson($this->commentsUrl($workspace, $entry), [
                'body' => 'Worth revisiting next quarter.',
            ])
            ->assertCreated();

        // The notification is addressed to the entry's author, not to the person
        // who wrote the comment.
        $notification = $workspace->owner->notifications()->sole();

        $this->assertSame(CommentedOnEntry::class, $notification->type);

        $data = $notification->data;

        $this->assertSame('comment', $data['kind']);
        $this->assertSame($member->name, $data['actor_name']);
        $this->assertSame($entry->title, $data['entry_title']);
        $this->assertSame($workspace->id, $data['workspace_id']);
        $this->assertSame($entry->id, $data['entry_id']);
        $this->assertSame(Comment::sole()->id, $data['comment_id']);
        $this->assertNull($data['reply_id']);
        $this->assertNull($notification->read_at);
    }

    public function test_replying_notifies_the_comment_author_and_carries_both_the_comment_and_the_reply(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $comment = $entry->comments()->create([
            'user_id' => $member->id,
            'body' => 'Is this still current?',
        ]);

        $this->actingAs($workspace->owner)
            ->postJson(
                $this->commentsUrl($workspace, $entry)."/{$comment->id}/replies",
                ['body' => 'Yes, updated last week.'],
            )
            ->assertCreated();

        // Addressed to the commenter, who is the one with an answer to give,
        // and not to the entry's author who is the replier in this case.
        $notification = $member->fresh()->notifications()->sole();

        $this->assertSame(RepliedToComment::class, $notification->type);

        $data = $notification->data;

        $this->assertSame('reply', $data['kind']);
        $this->assertSame($workspace->id, $data['workspace_id']);
        $this->assertSame($entry->id, $data['entry_id']);
        $this->assertSame($comment->id, $data['comment_id']);
        $this->assertSame($reply = $comment->replies()->sole()->id, $data['reply_id']);
    }

    public function test_a_user_is_not_notified_about_their_own_comment(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        // The owner writes the comment on their own entry.
        $this->actingAs($workspace->owner)
            ->postJson($this->commentsUrl($workspace, $entry), [
                'body' => 'A note to myself.',
            ])
            ->assertCreated();

        $this->assertSame(0, $workspace->owner->notifications()->count());
    }

    public function test_a_user_is_not_notified_about_their_own_reply(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $owner = $workspace->owner;

        $comment = $entry->comments()->create([
            'user_id' => $owner->id,
            'body' => 'My own comment.',
        ]);

        $this->actingAs($owner)
            ->postJson(
                $this->commentsUrl($workspace, $entry)."/{$comment->id}/replies",
                ['body' => 'My own reply.'],
            )
            ->assertCreated();

        $this->assertSame(0, $owner->fresh()->notifications()->count());
    }

    public function test_reading_a_discussion_does_not_send_notifications(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $comment = $entry->comments()->create([
            'user_id' => $member->id,
            'body' => 'Something to read.',
        ]);

        // Opening a thread is the most frequent action on the page. If it
        // notified anyone, reading a discussion would generate a notification
        // per reader per visit.
        $this->actingAs($member)
            ->getJson($this->commentsUrl($workspace, $entry))
            ->assertOk();

        $this->actingAs($workspace->owner)
            ->getJson($this->commentsUrl($workspace, $entry)."/{$comment->id}/replies")
            ->assertOk();

        $this->assertSame(0, $member->notifications()->count());
        $this->assertSame(0, $workspace->owner->notifications()->count());
    }

    public function test_the_list_returns_the_readers_own_notifications_newest_first(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $older = $entry->comments()->create([
            'user_id' => $member->id,
            'body' => 'The first thing said.',
        ]);

        // Timestamps are set explicitly because two rows written inside the same
        // second are not meaningfully ordered, and the assertion below is about
        // ordering, not about which of two simultaneous comments wins.
        $workspace->owner->notifications()->create([
            'id' => '11111111-1111-4111-8111-111111111111',
            'type' => CommentedOnEntry::class,
            'data' => [
                'kind' => 'comment',
                'actor_name' => $member->name,
                'workspace_id' => $workspace->id,
                'entry_id' => $entry->id,
                'comment_id' => $older->id,
                'reply_id' => null,
            ],
            'read_at' => null,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $newer = $entry->comments()->create([
            'user_id' => $member->id,
            'body' => 'The second thing said.',
        ]);

        $workspace->owner->notifications()->create([
            'id' => '22222222-2222-4222-8222-222222222222',
            'type' => CommentedOnEntry::class,
            'data' => [
                'kind' => 'comment',
                'actor_name' => $member->name,
                'workspace_id' => $workspace->id,
                'entry_id' => $entry->id,
                'comment_id' => $newer->id,
                'reply_id' => null,
            ],
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($workspace->owner)->getJson('/api/v1/notifications');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            // Newest first: the panel shows the most recent at the top.
            ->assertJsonPath('data.0.comment_id', $newer->id)
            ->assertJsonPath('data.1.comment_id', $older->id)
            ->assertJsonPath('data.0.kind', 'comment')
            ->assertJsonPath('data.0.read_at', null);
    }

    public function test_the_list_never_returns_another_users_notifications(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $this->postComment($member, $workspace, $entry, 'Only the owner should see this.');

        $this->assertSame(1, $workspace->owner->notifications()->count());

        $response = $this->actingAs($member)->getJson('/api/v1/notifications');

        $response->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')
            ->assertUnauthorized();
    }

    public function test_the_list_is_capped(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        for ($index = 0; $index < 40; $index++) {
            $comment = $entry->comments()->create([
                'user_id' => $workspace->owner->id,
                'body' => "Comment {$index}",
            ]);

            $workspace->owner->notifications()->create([
                'id' => sprintf('00000000-0000-4000-8000-%012d', $index),
                'type' => CommentedOnEntry::class,
                'data' => [
                    'kind' => 'comment',
                    'entry_id' => $entry->id,
                    'comment_id' => $comment->id,
                ],
            ]);
        }

        // The default cap, and a request for more than the cap, both stop at 30.
        $this->actingAs($workspace->owner)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(30, 'data');

        $this->actingAs($workspace->owner)
            ->getJson('/api/v1/notifications?limit=500')
            ->assertOk()
            ->assertJsonCount(30, 'data');

        $this->actingAs($workspace->owner)
            ->getJson('/api/v1/notifications?limit=3')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_marking_all_as_read_marks_every_unread_notification_of_the_reader(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $comment = $this->postComment($member, $workspace, $entry, 'Something worth reading.');

        $this->actingAs($workspace->owner)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 1);

        $this->assertNotNull($workspace->owner->notifications()->sole()->read_at);

        // Marked read, so the panel no longer renders it as unread. The
        // serialized value is checked for presence rather than equality, because
        // it is a timestamp string on the way out and a Carbon in the database.
        $this->actingAs($workspace->owner)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.read_at', $workspace->owner->notifications()->sole()->read_at->toJSON());

        // Idempotent: a second press marks nothing, because there is nothing
        // unread left.
        $this->actingAs($workspace->owner)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 0);

        $this->assertNotNull($comment->exists);
    }

    public function test_marking_all_as_read_leaves_other_users_notifications_unread(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $memberComment = $this->postComment($member, $workspace, $entry, 'For the owner.');

        // The owner's own comment on their own entry notifies nobody, so the
        // member's notification has to come from a reply to their comment.
        $this->actingAs($workspace->owner)
            ->postJson(
                $this->commentsUrl($workspace, $entry)."/{$memberComment->id}/replies",
                ['body' => 'Answering the member.'],
            )
            ->assertCreated();

        $this->assertSame(1, $member->notifications()->count());
        $this->assertSame(1, $workspace->owner->notifications()->count());

        $this->actingAs($member)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 1);

        $this->assertNotNull($member->fresh()->notifications()->sole()->read_at);
        $this->assertNull($workspace->owner->fresh()->notifications()->sole()->read_at);
    }

    public function test_marking_all_as_read_requires_authentication(): void
    {
        $this->postJson('/api/v1/notifications/read-all')
            ->assertUnauthorized();
    }

    public function test_a_notification_created_by_the_database_channel_is_listed(): void
    {
        Notification::fake();

        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $this->actingAs($member)
            ->postJson($this->commentsUrl($workspace, $entry), ['body' => 'Hello.'])
            ->assertCreated();

        // With the notification faked the send is recorded rather than written,
        // so this asserts the class and the recipient rather than the row.
        Notification::assertSentTo(
            $workspace->owner,
            CommentedOnEntry::class,
            function (CommentedOnEntry $notification) use ($member) {
                return $notification->via($member) === ['database', 'mail'];
            },
        );

        Notification::assertNotSentTo($member, CommentedOnEntry::class);
    }

    public function test_a_mail_copy_is_rendered_for_the_entry_author(): void
    {
        Notification::fake();

        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $this->actingAs($member)
            ->postJson($this->commentsUrl($workspace, $entry), ['body' => 'Ping.'])
            ->assertCreated();

        Notification::assertSentTo(
            $workspace->owner,
            CommentedOnEntry::class,
            function (CommentedOnEntry $notification) use ($workspace, $entry) {
                $mail = $notification->toMail($workspace->owner);

                // The excerpt is the second line, but the first line is the
                // sentence introducing it, so the whole body is searched rather
                // than one index of it.
                $body = implode("\n", $mail->introLines);

                return str_contains((string) $mail->subject, $entry->title)
                    && str_contains($body, 'Ping.')
                    // The action button points at the comment, not just the
                    // entry, so an email is as deep-linked as the panel.
                    && str_contains((string) $mail->actionUrl, "entries/{$entry->id}")
                    && str_contains((string) $mail->actionUrl, 'comment=');
            },
        );
    }

    public function test_a_reply_mail_points_at_the_reply(): void
    {
        Notification::fake();

        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $comment = $entry->comments()->create([
            'user_id' => $member->id,
            'body' => 'Question?',
        ]);

        $this->actingAs($workspace->owner)
            ->postJson(
                $this->commentsUrl($workspace, $entry)."/{$comment->id}/replies",
                ['body' => 'Answer.'],
            )
            ->assertCreated();

        Notification::assertSentTo(
            $member,
            RepliedToComment::class,
            function (RepliedToComment $notification) use ($member, $comment) {
                $mail = $notification->toMail($member);

                $body = implode("\n", $mail->introLines);

                return str_contains((string) $mail->actionUrl, "comment={$comment->id}")
                    && str_contains((string) $mail->actionUrl, 'reply=')
                    && str_contains($body, 'Answer.');
            },
        );
    }
}
