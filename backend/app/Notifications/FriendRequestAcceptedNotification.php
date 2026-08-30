<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Pushed live to the original requester's default private channel
 * ("App.Models.User.{id}", same channel FriendRequestNotification already
 * uses - see routes/channels.php) plus persisted to the database, same
 * shape/reasoning as FriendRequestNotification. Without this, the
 * requester's Friends page never learns their request was accepted except
 * by some other action happening to trigger a refetch.
 */
class FriendRequestAcceptedNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(public User $accepter) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'accepter_user_id' => $this->accepter->id,
            'accepter_username' => $this->accepter->username ?? $this->accepter->name,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * The "type" field Echo's channel.notification() callback receives -
     * lets a shared per-user channel carry more than one kind of
     * notification (see FriendRequestNotification::broadcastType()).
     */
    public function broadcastType(): string
    {
        return 'friend.request_accepted';
    }
}
