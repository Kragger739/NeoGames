<?php

namespace App\Enums;

/**
 * Whether a room is capped at one player or open to more - orthogonal to
 * GameMode (which round-win rules apply), not one of its cases. Solo used
 * to be a GameMode value, but never actually restricted player count; this
 * is what gives "solo" real enforcement (see RoomPlayerController::store()).
 */
enum RoomPlayerMode: string
{
    case Solo = 'solo';
    case Multiplayer = 'multiplayer';
}
