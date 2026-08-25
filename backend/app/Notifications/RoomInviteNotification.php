<?php

namespace App\Notifications;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Pushed live via the recipient's default private channel
 * ("App.Models.User.{id}", per Notifiable's own convention - see
 * routes/channels.php) plus persisted to the database so it's still there
 * if they reconnect later.
 */
class RoomInviteNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(public User $from, public GameRoom $room) {}

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
            'room_code' => $this->room->code,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * The "type" field Echo's channel.notification() callback receives -
     * set here, not chained on BroadcastMessage (which has no such method).
     */
    public function broadcastType(): string
    {
        return 'room.invite';
    }
}
