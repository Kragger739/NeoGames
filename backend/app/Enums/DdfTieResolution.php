<?php

namespace App\Enums;

enum DdfTieResolution: string
{
    case Vote = 'vote';
    case Revote = 'revote';
    case GmDecision = 'gm_decision';
}
