export interface RoundStartedPayload {
  round_id: number;
  audio_url: string | null;
  stage: number;
  tier: string;
  round_number: number;
  total_rounds: number;
  server_time: string;
}

export type RoundStageAdvancedPayload = RoundStartedPayload;

export interface RevealedAnswer {
  title: string;
  artist: string;
  album_art_url: string | null;
  artist_fan_count: number | null;
  deezer_track_id: string;
}

export interface RoundWonPayload {
  round_id: number;
  winner_id: number;
  winner_nickname: string;
  winner_level: number | null;
  points: number;
  answer: RevealedAnswer;
  scoreboard: ScoreboardEntry[];
}

export interface RoundFailedPayload {
  round_id: number;
  answer: RevealedAnswer;
}

export interface GuessMissedPayload {
  round_id: number;
  nickname: string;
}

export interface TierAdvancedPayload {
  tier: string;
  round_number: number;
  total_rounds: number;
}

import type { AvatarData } from "./avatarData";

export interface ScoreboardEntry {
  id: number;
  nickname: string;
  score: number;
  is_eliminated: boolean;
  level: number | null;
  avatar: AvatarData | null;
}

export interface GameFinishedPayload {
  scoreboard: ScoreboardEntry[];
}

export interface RoomResetPayload {
  players: ScoreboardEntry[];
}

export interface RoomSettingsUpdatedPayload {
  songs_per_tier: number;
  enabled_tiers: string[];
  guess_timeout_seconds: number;
  mode: string;
  player_mode: string;
  genre: string;
  year_from: number | null;
  year_to: number | null;
  artist_name: string | null;
  artist_names: string[] | null;
  dataset_id: number | null;
  dataset_name: string | null;
}

export interface BattleRoyalePlayer {
  id: number;
  nickname: string;
  level: number | null;
  avatar: AvatarData | null;
}

export interface BattleRoyaleRoundResolvedPayload {
  round_id: number;
  survivors: BattleRoyalePlayer[];
  eliminated: BattleRoyalePlayer[];
  answer: RevealedAnswer;
  scoreboard: ScoreboardEntry[];
}
