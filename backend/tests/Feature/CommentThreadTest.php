<?php

namespace Tests\Feature;

use App\Http\Resources\ReplyResource;
use App\Models\Comment;
use App\Models\KnowledgeEntry;
use App\Models\Reply;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CommentThreadTest extends TestCase
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
            'title' => 'An entry',
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

    private function repliesUrl(
        Workspace $workspace,
        KnowledgeEntry $entry,
        Comment $comment
    ): string {
        return $this->commentsUrl($workspace, $entry)."/{$comment->id}/replies";
    }

    private function comment(
        KnowledgeEntry $entry,
        User $author,
        string $body = 'A comment.'
    ): Comment {
        return $entry->comments()->create([
            'user_id' => $author->id,
            'body' => $body,
        ]);
    }

    public function test_a_member_can_comment_and_the_comment_is_returned_with_its_author(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $response = $this->actingAs($member)
            ->postJson($this->commentsUrl($workspace, $entry), [
                'body' => 'Worth revisiting next quarter.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.body', 'Worth revisiting next quarter.')
            ->assertJsonPath('data.user_id', $member->id)
            ->assertJsonPath('data.knowledge_entry_id', $entry->id)
            ->assertJsonPath('data.user.name', $member->name);

        $this->assertSame(1, Comment::count());
    }

    public function test_comments_are_listed_oldest_first_with_their_replies_loaded(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $first = $this->comment($entry, $workspace->owner, 'First');
        $second = $this->comment($entry, $member, 'Second');

        $second->replies()->create([
            'user_id' => $workspace->owner->id,
            'body' => 'A reply',
        ]);

        $response = $this->actingAs($workspace->owner)
            ->getJson($this->commentsUrl($workspace, $entry));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            // Oldest first: the discussion reads top to bottom.
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id)
            // Replies are nested under their comment rather than flattened into
            // the top level, so the client never has to regroup them.
            ->assertJsonCount(1, 'data.1.replies')
            ->assertJsonPath('data.1.replies.0.body', 'A reply');
    }

    public function test_an_author_can_reply_to_a_comment(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');

        $this->actingAs($member)
            ->postJson($this->repliesUrl($workspace, $entry, $comment), [
                'body' => 'Agreed.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Agreed.')
            ->assertJsonPath('data.comment_id', $comment->id)
            ->assertJsonPath('data.user_id', $member->id);

        $this->assertSame(1, Reply::count());
    }

    public function test_a_viewer_can_take_part_in_the_discussion(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner);
        $viewer = $this->attach($workspace, User::factory()->create(), 'viewer');

        $this->actingAs($viewer)
            ->getJson($this->commentsUrl($workspace, $entry))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($viewer)
            ->postJson($this->commentsUrl($workspace, $entry), ['body' => 'Worth noting.'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Worth noting.')
            ->assertJsonPath('data.user_id', $viewer->id);

        $this->actingAs($viewer)
            ->postJson($this->repliesUrl($workspace, $entry, $comment), ['body' => 'Agreed.'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Agreed.')
            ->assertJsonPath('data.comment_id', $comment->id);

        $this->assertSame(2, Comment::count());
        $this->assertSame(1, Reply::count());
    }

    public function test_a_non_member_cannot_read_or_post_to_the_discussion(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->getJson($this->commentsUrl($workspace, $entry))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->postJson($this->commentsUrl($workspace, $entry), ['body' => 'Hello'])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->postJson($this->repliesUrl($workspace, $entry, $comment), ['body' => 'Hello'])
            ->assertForbidden();

        $this->assertSame(1, Comment::count());
    }

    public function test_guests_cannot_reach_the_discussion(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->getJson($this->commentsUrl($workspace, $entry))
            ->assertUnauthorized();

        $this->postJson($this->commentsUrl($workspace, $entry), ['body' => 'Hello'])
            ->assertUnauthorized();
    }

    public function test_an_author_can_edit_their_own_comment_but_another_member_cannot(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');
        $comment = $this->comment($entry, $member, 'Typo');

        $this->actingAs($member)
            ->patchJson($this->commentsUrl($workspace, $entry)."/{$comment->id}", [
                'body' => 'Fixed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.body', 'Fixed.');

        // Another member has write access to the entry but not authorship of
        // this comment, so editing it is refused.
        $other = $this->attach($workspace, User::factory()->create(), 'member');

        $this->actingAs($other)
            ->patchJson($this->commentsUrl($workspace, $entry)."/{$comment->id}", [
                'body' => 'Rewritten.',
            ])
            ->assertForbidden();

        $this->assertSame('Fixed.', $comment->fresh()->body);
    }

    public function test_the_author_can_delete_their_own_comment(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');
        $comment = $this->comment($entry, $member);

        $this->actingAs($member)
            ->deleteJson($this->commentsUrl($workspace, $entry)."/{$comment->id}")
            ->assertOk();

        $this->assertSame(0, Comment::count());
    }

    public function test_the_workspace_owner_can_delete_any_comment_for_moderation(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');
        $comment = $this->comment($entry, $member);

        $this->actingAs($workspace->owner)
            ->deleteJson($this->commentsUrl($workspace, $entry)."/{$comment->id}")
            ->assertOk();

        $this->assertSame(0, Comment::count());
    }

    public function test_another_member_cannot_delete_someone_elses_comment(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $author = $this->attach($workspace, User::factory()->create(), 'member');
        $other = $this->attach($workspace, User::factory()->create(), 'member');
        $comment = $this->comment($entry, $author);

        // Write access to the entry is not enough: otherwise a member could
        // delete the comment someone disagreed with and quietly remove the
        // disagreement from the discussion.
        $this->actingAs($other)
            ->deleteJson($this->commentsUrl($workspace, $entry)."/{$comment->id}")
            ->assertForbidden();

        $this->assertSame(1, Comment::count());
    }

    public function test_deleting_a_comment_deletes_its_replies(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner);

        $comment->replies()->create([
            'user_id' => $workspace->owner->id,
            'body' => 'A reply',
        ]);

        $this->actingAs($workspace->owner)
            ->deleteJson($this->commentsUrl($workspace, $entry)."/{$comment->id}")
            ->assertOk();

        $this->assertSame(0, Comment::count());
        $this->assertSame(0, Reply::count());
    }

    public function test_the_author_can_delete_their_own_reply_and_the_owner_can_delete_any(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');
        $comment = $this->comment($entry, $workspace->owner);

        $ownReply = $comment->replies()->create([
            'user_id' => $member->id,
            'body' => 'Mine',
        ]);

        $this->actingAs($member)
            ->deleteJson($this->repliesUrl($workspace, $entry, $comment)."/{$ownReply->id}")
            ->assertOk();

        $otherReply = $comment->replies()->create([
            'user_id' => $member->id,
            'body' => 'Also mine, but deleted by the owner',
        ]);

        $this->actingAs($workspace->owner)
            ->deleteJson($this->repliesUrl($workspace, $entry, $comment)."/{$otherReply->id}")
            ->assertOk();

        $this->assertSame(0, Reply::count());
        // The comment survives its replies.
        $this->assertSame(1, Comment::count());
    }

    public function test_another_member_cannot_delete_someone_elses_reply(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $author = $this->attach($workspace, User::factory()->create(), 'member');
        $other = $this->attach($workspace, User::factory()->create(), 'member');
        $comment = $this->comment($entry, $workspace->owner);
        $reply = $comment->replies()->create([
            'user_id' => $author->id,
            'body' => 'Mine',
        ]);

        $this->actingAs($other)
            ->deleteJson($this->repliesUrl($workspace, $entry, $comment)."/{$reply->id}")
            ->assertForbidden();

        $this->assertSame(1, Reply::count());
    }

    public function test_a_comment_cannot_be_reached_through_another_entry(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $otherEntry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner);

        // Same workspace and same author, so this can only be caught by checking
        // the comment belongs to the entry in the URL.
        $this->actingAs($workspace->owner)
            ->patchJson($this->commentsUrl($workspace, $otherEntry)."/{$comment->id}", [
                'body' => 'Rewritten.',
            ])
            ->assertNotFound();

        $this->actingAs($workspace->owner)
            ->deleteJson($this->commentsUrl($workspace, $otherEntry)."/{$comment->id}")
            ->assertNotFound();

        $this->assertSame(1, Comment::count());
    }

    public function test_a_comment_from_another_workspace_is_not_found(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner);

        $otherWorkspace = $this->workspace();
        $otherEntry = $this->entry($otherWorkspace, $otherWorkspace->owner);

        $this->actingAs($otherWorkspace->owner)
            ->deleteJson($this->commentsUrl($otherWorkspace, $otherEntry)."/{$comment->id}")
            ->assertNotFound();

        $this->assertSame(1, Comment::count());
    }

    public function test_a_reply_cannot_be_reached_through_another_comment(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner, 'Mine');
        $otherComment = $this->comment($entry, $workspace->owner, 'Someone elses');
        $reply = $comment->replies()->create([
            'user_id' => $workspace->owner->id,
            'body' => 'A reply',
        ]);

        // Both comments belong to the same entry and the same author, so only a
        // check against the comment in the URL can catch this.
        $this->actingAs($workspace->owner)
            ->deleteJson($this->repliesUrl($workspace, $entry, $otherComment)."/{$reply->id}")
            ->assertNotFound();

        $this->assertSame(1, Reply::count());
    }

    public function test_a_reply_cannot_be_posted_to_a_comment_in_another_entry(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $otherEntry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->repliesUrl($workspace, $otherEntry, $comment), [
                'body' => 'Sneaky',
            ])
            ->assertNotFound();

        $this->assertSame(0, Reply::count());
    }

    public function test_an_empty_body_is_rejected(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner);

        // Whitespace alone would otherwise render as a blank post that still
        // occupies a row and a line in the discussion.
        $this->actingAs($workspace->owner)
            ->postJson($this->commentsUrl($workspace, $entry), ['body' => "   \n  "])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->actingAs($workspace->owner)
            ->postJson($this->commentsUrl($workspace, $entry), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->actingAs($workspace->owner)
            ->postJson($this->repliesUrl($workspace, $entry, $comment), ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame(1, Comment::count());
        $this->assertSame(0, Reply::count());
    }

    public function test_an_overlong_body_is_rejected(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);

        $this->actingAs($workspace->owner)
            ->postJson($this->commentsUrl($workspace, $entry), [
                'body' => str_repeat('a', 5001),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, Comment::count());
    }

    public function test_a_comment_outlives_the_account_that_wrote_it(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $member = $this->attach($workspace, User::factory()->create(), 'member');
        $comment = $this->comment($entry, $member, 'Still here');

        $member->delete();

        // The discussion keeps its shape instead of losing a turn, so the
        // author is nullable and the client falls back to a placeholder.
        $this->assertSame(1, Comment::count());

        $this->actingAs($workspace->owner)
            ->getJson($this->commentsUrl($workspace, $entry))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Still here')
            ->assertJsonPath('data.0.user_id', null);

        // And nobody inherits the ability to edit it, since authorship is gone.
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->patchJson($this->commentsUrl($workspace, $entry)."/{$comment->id}", [
                'body' => 'Hijacked.',
            ])
            ->assertForbidden();
    }

    public function test_deleting_an_entry_removes_its_discussion(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner);

        $comment->replies()->create([
            'user_id' => $workspace->owner->id,
            'body' => 'A reply',
        ]);

        $entry->delete();

        $this->assertSame(0, Comment::count());
        $this->assertSame(0, Reply::count());
    }

    public function test_replies_cannot_nest_further(): void
    {
        $workspace = $this->workspace();
        $entry = $this->entry($workspace, $workspace->owner);
        $comment = $this->comment($entry, $workspace->owner);
        $reply = $comment->replies()->create([
            'user_id' => $workspace->owner->id,
            'body' => 'A reply',
        ]);

        // No route nests under a reply, so nothing can produce a second level.
        // Asserted against the reply routes by shape rather than by a 405,
        // because the absence is the property being relied on: the client
        // renders one flat level, so a route appearing later would be a change
        // to the contract rather than a bug.
        $replyRoutes = collect(Route::getRoutes())->filter(
            fn ($route) => str_contains($route->uri(), '/comments/{comment}/replies')
        );

        $this->assertNotEmpty($replyRoutes);

        foreach ($replyRoutes as $route) {
            $this->assertStringNotContainsString(
                '/replies/{reply}/replies',
                $route->uri(),
                'A route reaches a reply through another reply.'
            );
        }

        // And the resource itself has nowhere to nest to.
        $this->assertArrayNotHasKey(
            'replies',
            (new ReplyResource($reply))->toArray(request())
        );
    }
}
