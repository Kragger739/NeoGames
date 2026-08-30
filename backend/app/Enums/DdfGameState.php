<?php

namespace App\Enums;

enum DdfGameState: string
{
    case Lobby = 'lobby';
    case GameStart = 'game_start';
    case Question = 'question';
    case AnswerSubmitted = 'answer_submitted';
    case QuestionResult = 'question_result';
    case RoundComplete = 'round_complete';
    case Voting = 'voting';
    case VotingResults = 'voting_results';
    case LifeLost = 'life_lost';
    case Elimination = 'elimination';
    case GameOver = 'game_over';
}
