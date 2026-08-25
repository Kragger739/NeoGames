<?php

namespace App\Enums;

enum RoundStatus: string
{
    case Playing = 'playing';
    case Won = 'won';
    case Failed = 'failed';
}
