export type DdfLanguage = "en" | "de";

export type DdfGameState =
  | "lobby"
  | "game_start"
  | "question"
  | "answer_submitted"
  | "question_result"
  | "round_complete"
  | "voting"
  | "voting_results"
  | "life_lost"
  | "elimination"
  | "game_over";

export interface DdfPlayerSummary {
  room_player_id: number;
  nickname: string;
  hearts: number;
  is_eliminated: boolean;
  is_camera_ready: boolean;
  level: number | null;
}

export interface DdfCurrentQuestion {
  id: number;
  text: string;
  category: string;
  number: number;
}

/** One camera-tile dot: a question this player was asked this voting cycle. */
export interface DdfCycleAnswer {
  questionNumber: number;
  questionText: string;
  isCorrect: boolean | null;
}

/** GM-only companion to DdfRoomState (GET /api/ddf-rooms/{code}/gm-state). */
export interface DdfGmState {
  correct_answer: string | null;
  cycle_answers: DdfRoomState["cycle_answers"];
  gm_answers: Array<{
    room_player_id: number;
    nickname: string | null;
    answer_text: string | null;
    submitted_at: string | null;
  }>;
}

/** GET /api/ddf-rooms/{code} - the catch-up/reconnect payload. */
export interface DdfRoomState {
  code: string;
  host_id: number;
  host_name: string;
  state: DdfGameState;
  rounds_per_voting: number;
  rounds_played_this_cycle: number;
  question_timer_seconds: number;
  voting_timer_seconds: number;
  language: DdfLanguage;
  couch_mode: boolean;
  safe_mode: boolean;
  dataset_id: number | null;
  dataset_name: string | null;
  current_turn_room_player_id: number | null;
  current_question: DdfCurrentQuestion | null;
  is_paused: boolean;
  players: DdfPlayerSummary[];
  winner_room_player_id: number | null;
  cycle_answers: Record<
    string,
    Array<{ question_number: number; question_text: string; is_correct: boolean | null }>
  >;
  server_time: string;
}
