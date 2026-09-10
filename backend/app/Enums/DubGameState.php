<?php

namespace App\Enums;

enum DubGameState: string
{
    case Lobby = 'lobby';
    case RoleClaim = 'role_claim';
    case Recording = 'recording';
    case Assembling = 'assembling';
    case Watch = 'watch';
    case Rating = 'rating';
    case RoundComplete = 'round_complete';
    case Finished = 'finished';
}
