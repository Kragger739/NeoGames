<?php

namespace App\Enums;

enum DdfQuestionCategory: string
{
    case History = 'history';
    case Geography = 'geography';
    case Science = 'science';
    case Math = 'math';
    case Sports = 'sports';
    case MoviesTv = 'movies_tv';
    case Music = 'music';
    case Animals = 'animals';
    case Technology = 'technology';
    case Culture = 'culture';
    case EverydayKnowledge = 'everyday_knowledge';
}
