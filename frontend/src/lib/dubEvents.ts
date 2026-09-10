import type {
  DubClip,
  DubLine,
  DubPlayerSummary,
  DubRoleAssignment,
  DubRoundSummary,
  DubScoreBreakdownEntry,
} from "./dubTypes";

interface WithServerTime {
  server_time: string;
}

export interface DubPlayersUpdatedPayload extends WithServerTime {
  players: DubPlayerSummary[];
}

export interface DubClipSelectedPayload extends WithServerTime {
  clip: DubClip | null;
}

export interface DubRoleClaimStartedPayload extends WithServerTime {
  round_number: number;
  clip: DubClip | null;
  assignments: DubRoleAssignment[];
}

export interface DubRoleClaimUpdatedPayload extends WithServerTime {
  assignments: DubRoleAssignment[];
  players: DubPlayerSummary[];
}

export interface DubLinePayload extends WithServerTime {
  current_line_index: number;
  total_lines: number;
  line: DubLine | null;
  assigned_room_player_id: number | null;
  timer_seconds: number;
}

export interface DubTakeRecordedPayload extends WithServerTime {
  line_id: number;
  room_player_id: number;
  take_id: number;
  duration_ms: number | null;
}

export interface DubAssemblyStartedPayload extends WithServerTime {
  round_number: number;
}

export interface DubAssembledPayload extends WithServerTime {
  round_number: number;
  assembled_video_url: string | null;
}

export interface DubAssemblyFailedPayload extends WithServerTime {
  round_number: number;
  error: string | null;
}

export interface DubRatingStartedPayload extends WithServerTime {
  round_number: number;
  assembled_video_url: string | null;
}

export interface DubRatingProgressPayload extends WithServerTime {
  count: number;
  total: number;
}

export interface DubRoundScoredPayload extends WithServerTime {
  round_number: number;
  last_round_score: number | null;
  total_score: number;
  breakdown: DubScoreBreakdownEntry[];
}

export interface DubNextRoundPayload extends WithServerTime {
  round_number: number;
  clip: DubClip | null;
  assignments: DubRoleAssignment[];
}

export interface DubGameFinishedPayload extends WithServerTime {
  total_score: number;
  rounds: DubRoundSummary[];
}

export interface DubGameResetPayload extends WithServerTime {
  players: DubPlayerSummary[];
}
