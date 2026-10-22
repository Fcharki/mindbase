<?php

namespace App\Notifications;

use App\Models\MembershipRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JoinRequestReceived extends Notification
{
    use Queueable;

    /**
     * @param  MembershipRequest  $membershipRequest  The request just created.
     * @param  User  $actor  Who asked to join.
     * @param  Workspace  $workspace  The workspace whose owner is being told.
     */
    public function __construct(
        private readonly MembershipRequest $membershipRequest,
        private readonly User $actor,
        private readonly Workspace $workspace,
    ) {}

    /**
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
            'kind' => 'join_request',
            'actor_username' => $this->actor->username,
            'workspace_id' => $this->workspace->id,
            'workspace_name' => $this->workspace->name,
            'membership_request_id' => $this->membershipRequest->id,
            'requested_role' => $this->membershipRequest->requested_role,
            'excerpt' => "Requested to join as {$this->membershipRequest->requested_role}.",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->actor->username} requested to join \"{$this->workspace->name}\"")
            ->line("{$this->actor->username} asked to join \"{$this->workspace->name}\" as {$this->membershipRequest->requested_role}.")
            ->action('Review the request', $this->requestUrl());
    }

    private function requestUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            ."/workspaces/{$this->workspace->id}#join-requests";
    }
}
