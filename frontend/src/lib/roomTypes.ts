import type { AvatarData } from "./avatarData";

export interface RoomPlayerSummary {
  id: number;
  nickname: string;
  score: number;
  is_eliminated: boolean;
  level: number | null;
  avatar: AvatarData | null;
}

export interface SongHistoryGuesser {
  nickname: string;
  level: number | null;
  snippet_stage: number;
}

export interface SongHistoryEntry {
  round_id: number;
  song: {
    title: string;
    artist: string;
    album_art_url: string | null;
    provider_track_id: string;
  };
  guessers: SongHistoryGuesser[];
}

export interface SongHistoryResponse {
  rounds: SongHistoryEntry[];
}

export interface CurrentRoundSummary {
  round_id: number;
  audio_url: string | null;
  stage: number;
  tier: string;
  round_number: number;
  total_rounds: number;
  server_time: string;
}

export type GameMode = "classic" | "battle_royale" | "custom";

// Orthogonal to GameMode - whether the room is capped at one player or
// open to more. See RoomPlayerMode on the backend.
export type PlayerMode = "solo" | "multiplayer";

export type SongGenre =
  | "normal"
  | "pop"
  | "hip_hop"
  | "german_rap"
  | "artist"
  | "classics"
  | "year"
  | "multi_artist"
  // Backend-only, non-user-selectable - forced automatically whenever
  // mode is "classic" (see RoomSettingsForm.tsx). Deliberately absent
  // from SONG_GENRES, so it never appears as a pickable option.
  | "iconic";

export interface RoomState {
  code: string;
  host_id: number;
  status: "lobby" | "active" | "finished";
  mode: GameMode;
  player_mode: PlayerMode;
  genre: SongGenre;
  year_from: number | null;
  year_to: number | null;
  artist_name: string | null;
  artist_names: string[] | null;
  songs_per_tier: number;
  enabled_tiers: string[];
  guess_timeout_seconds: number;
  dataset_id: number | null;
  dataset_name: string | null;
  daily_challenge_id: number | null;
  current_tier: string | null;
  current_song_index: number;
  players: RoomPlayerSummary[];
  current_round: CurrentRoundSummary | null;
}

/** Only present on room creation - the host's own player identity. */
export interface CreateRoomResponse extends RoomState {
  player: {
    id: number;
    connection_token: string;
    nickname: string;
  };
}

export interface PresenceMember {
  id: string | number;
  name: string;
  level: number | null;
  avatar: AvatarData | null;
}
