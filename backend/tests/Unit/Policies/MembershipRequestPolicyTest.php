<?php

namespace Tests\Unit\Policies;

use App\Models\MembershipRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\MembershipRequestPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A rejection here is a 409 conflict with wording the client reads back, so
 * both the status and the message are pinned here rather than left to
 * whichever exception type happens to be thrown.
 */
class MembershipRequestPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function workspaceOwnedBy(User $owner): Workspace
    {
        return Workspace::create([
            'name' => 'Acme',
            'owner_id' => $owner->id,
        ]);
    }

    public function test_any_authenticated_user_may_ask_to_join_someone_elses_workspace(): void
    {
        $owner = User::factory()->create();
        $applicant = User::factory()->create();
        $workspace = $this->workspaceOwnedBy($owner);

        $this->assertTrue(
            (new MembershipRequestPolicy)->create($applicant, $workspace)
        );
    }

    public function test_the_owner_is_told_they_already_own_the_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspaceOwnedBy($owner);

        try {
            (new MembershipRequestPolicy)->create($owner, $workspace);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('You already own this workspace.', $exception->getMessage());
            $this->assertSame(409, $exception->status());
        }
    }

    public function test_an_existing_member_is_told_they_are_already_a_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $workspace = $this->workspaceOwnedBy($owner);
        $workspace->members()->attach($member->id, ['role' => 'member']);

        try {
            (new MembershipRequestPolicy)->create($member, $workspace);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'You are already a member of this workspace.',
                $exception->getMessage()
            );
            $this->assertSame(409, $exception->status());
        }
    }

    public function test_a_second_request_is_told_one_is_already_pending(): void
    {
        $owner = User::factory()->create();
        $applicant = User::factory()->create();
        $workspace = $this->workspaceOwnedBy($owner);

        MembershipRequest::create([
            'workspace_id' => $workspace->id,
            'user_id' => $applicant->id,
            'status' => MembershipRequest::STATUS_PENDING,
            'requested_role' => 'member',
        ]);

        try {
            (new MembershipRequestPolicy)->create($applicant, $workspace);
            $this->fail('Expected an AuthorizationException.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(
                'You already have a pending request for this workspace.',
                $exception->getMessage()
            );
            $this->assertSame(409, $exception->status());
        }
    }

    public function test_a_resolved_request_does_not_block_a_fresh_one(): void
    {
        $owner = User::factory()->create();
        $applicant = User::factory()->create();
        $workspace = $this->workspaceOwnedBy($owner);

        MembershipRequest::create([
            'workspace_id' => $workspace->id,
            'user_id' => $applicant->id,
            'status' => MembershipRequest::STATUS_REJECTED,
            'requested_role' => 'member',
        ]);

        $this->assertTrue(
            (new MembershipRequestPolicy)->create($applicant, $workspace)
        );
    }
}
