<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Friendship extends Model
{
    protected $fillable = [
        'user_id',
        'friend_id',
        'status',
    ];

    /** The user who sent the request. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The user who received the request. */
    public function friend(): BelongsTo
    {
        return $this->belongsTo(User::class, 'friend_id');
    }
}
