<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Pushed live via the recipient's default private channel
 * ("App.Models.User.{id}", same channel RoomInviteNotification already
 * uses - see routes/channels.php) plus persisted to the database, same
 * shape/reasoning as RoomInviteNotification. Powers both the dashboard's
 * pending-request badge and the Friends page's live auto-refresh.
 */
class FriendRequestNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(public User $from) {}

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
            'from_user_id' => $this->from->id,
            'from_username' => $this->from->username ?? $this->from->name,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * The "type" field Echo's channel.notification() callback receives -
     * lets a shared per-user channel carry more than one kind of
     * notification (see RoomInviteNotification::broadcastType()).
     */
    public function broadcastType(): string
    {
        return 'friend.request_received';
    }
}
