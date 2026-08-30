<?php

namespace App\Enums;

enum GameMode: string
{
    case Classic = 'classic';
    case BattleRoyale = 'battle_royale';
    case Custom = 'custom';
}
