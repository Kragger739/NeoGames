import type { AvatarData } from "./avatarData";

export type DubGameState =
  | "lobby"
  | "role_claim"
  | "recording"
  | "assembling"
  | "watch"
  | "rating"
  | "round_complete"
  | "finished";

export interface DubCharacter {
  id: number;
  display_name: string;
  color: string | null;
  position: number;
}

export interface DubLine {
  id: number;
  position: number;
  character_id: number;
  start_ms: number;
  end_ms: number;
  text: string | null;
}

export interface DubClip {
  id: number;
  title: string;
  status: string;
  duration_ms: number | null;
  video_url: string | null;
  characters: DubCharacter[];
  lines: DubLine[];
}

export interface DubClipListItem {
  id: number;
  title: string;
  source: string;
  duration_ms: number | null;
  video_url: string | null;
  character_count: number;
  line_count: number;
}

export interface DubRoleAssignment {
  character_id: number;
  room_player_id: number | null;
}

export interface DubPlayerSummary {
  room_player_id: number;
  nickname: string;
  level: number | null;
  avatar: AvatarData | null;
  is_host: boolean;
  mic_ready: boolean;
  claimed_character_id: number | null;
}

export interface DubTakeSummary {
  line_id: number;
  room_player_id: number;
  take_id: number;
  duration_ms: number | null;
}

export interface DubScoreBreakdownEntry {
  room_player_id: number;
  nickname: string;
  score: number;
}

export interface DubRoundSummary {
  round_number: number;
  clip_title: string;
  score: number | null;
  video_url: string | null;
}

/** GET /api/dub-rooms/{code} */
export interface DubRoomState {
  code: string;
  host_id: number;
  host_name: string;
  state: DubGameState;
  round_number: number;
  total_score: number;
  last_round_score: number | null;
  line_timer_seconds: number;
  clip: DubClip | null;
  role_assignments: DubRoleAssignment[];
  current_line_index: number;
  current_line: DubLine | null;
  assigned_room_player_id: number | null;
  total_lines: number;
  takes: DubTakeSummary[];
  assembled_video_url: string | null;
  assembly_error: string | null;
  rating: { mine: number | null; count: number; total: number };
  players: DubPlayerSummary[];
  server_time: string;
}

/** POST /api/dub-rooms */
export interface CreateDubRoomResponse extends DubRoomState {
  host_player: { id: number; nickname: string; connection_token: string };
}
