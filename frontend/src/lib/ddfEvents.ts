import type { DdfGameState, DdfLanguage } from "./ddfTypes";

interface WithServerTime {
  server_time: string;
}

export interface DdfPlayersUpdatedPayload extends WithServerTime {
  players: Array<{
    room_player_id: number;
    nickname: string;
    hearts: number;
    is_eliminated: boolean;
    is_camera_ready: boolean;
    level: number | null;
  }>;
}

export interface DdfGameStartedPayload extends WithServerTime {
  state: DdfGameState;
  rounds_per_voting: number;
  question_timer_seconds: number;
  voting_timer_seconds: number;
  language: DdfLanguage;
  couch_mode: boolean;
  players: Array<{ room_player_id: number; nickname: string; hearts: number; is_eliminated: boolean }>;
}

export interface DdfQuestionStartedPayload extends WithServerTime {
  question_id: number;
  question_text: string;
  category: string;
  question_number: number;
  rounds_played_this_cycle: number;
  rounds_per_voting: number;
  timer_seconds: number;
  current_turn_room_player_id: number | null;
}

export interface DdfPlayerAnsweredPayload extends WithServerTime {
  room_player_id: number;
  all_answered: boolean;
}

/** GM-only (room.{code}.gm). */
export interface DdfAnswerSubmittedToGmPayload extends WithServerTime {
  room_player_id: number;
  nickname: string;
  answer_text: string | null;
  submitted_at: string | null;
}

/** GM-only (room.{code}.gm) - the current question's answer key, sent at question start. */
export interface DdfCorrectAnswerToGmPayload extends WithServerTime {
  question_id: number;
  question_number: number;
  correct_answer: string;
}

export interface DdfAnswersLockedPayload extends WithServerTime {
  question_id: number;
}

export interface DdfAnswerMarkedPayload extends WithServerTime {
  room_player_id: number;
  is_correct: boolean;
}

export interface DdfQuestionResultEntry {
  room_player_id: number;
  answer_text: string | null;
  is_correct: boolean | null;
}

export interface DdfQuestionResultPayload extends WithServerTime {
  question_id: number;
  correct_answer: string;
  skipped: boolean;
  results: DdfQuestionResultEntry[];
}

export interface DdfRoundCompletePayload extends WithServerTime {
  rounds_per_voting: number;
}

export interface DdfVotingStartedPayload extends WithServerTime {
  voting_round_number: number;
  is_revote: boolean;
  tie_candidate_player_ids: number[] | null;
  eligible_voter_ids: number[];
  eligible_target_ids: number[];
  timer_seconds: number;
}

/** GM-only (room.{code}.gm). */
export interface DdfVoteCastToGmPayload extends WithServerTime {
  voting_round_number: number;
  voter_room_player_id: number;
  target_room_player_id: number;
}

export interface DdfVotingProgressPayload extends WithServerTime {
  votes_cast: number;
  total_eligible: number;
}

export interface DdfVotingResultEntry {
  room_player_id: number;
  vote_count: number;
}

export interface DdfVotingResultsPayload extends WithServerTime {
  is_tie: boolean;
  resolved_by: "vote" | "revote" | "gm_decision" | null;
  loser_room_player_id: number | null;
  tied_player_ids: number[];
  awaiting_gm: boolean;
  results: DdfVotingResultEntry[];
}

/** GM-only (room.{code}.gm). */
export interface DdfTieNeedsResolutionPayload extends WithServerTime {
  tied_player_ids: number[];
}

export interface DdfLifeLostPayload extends WithServerTime {
  room_player_id: number;
  hearts_remaining: number;
}

export interface DdfPlayerEliminatedPayload extends WithServerTime {
  room_player_id: number;
  reason: "hearts_zero" | "gm_removed";
}

export interface DdfGamePausedPayload extends WithServerTime {
  remaining_seconds: number;
}

export interface DdfGameResumedPayload extends WithServerTime {
  remaining_seconds: number;
}

export interface DdfSettingsUpdatedPayload extends WithServerTime {
  rounds_per_voting: number;
  question_timer_seconds: number;
  voting_timer_seconds: number;
  language: DdfLanguage;
  couch_mode: boolean;
  safe_mode: boolean;
  dataset_id: number | null;
  dataset_name: string | null;
}

export interface DdfGameOverPayload extends WithServerTime {
  winner_room_player_id: number | null;
  winner_nickname: string | null;
  players: Array<{ room_player_id: number; nickname: string; hearts: number; is_eliminated: boolean }>;
}

export interface DdfGameResetPayload extends WithServerTime {
  players: Array<{ room_player_id: number; nickname: string; hearts: number; is_eliminated: boolean }>;
}
