export interface RoomPlayerSummary {
  id: number;
  nickname: string;
  score: number;
  is_eliminated: boolean;
}

export interface CurrentRoundSummary {
  round_id: number;
  audio_url: string | null;
  stage: number;
  tier: string;
  server_time: string;
}

export type GameMode = "classic" | "battle_royale" | "solo";

export type SongGenre = "normal" | "pop" | "hip_hop" | "german_rap" | "artist" | "classics" | "year";

export interface RoomState {
  code: string;
  status: "lobby" | "active" | "finished";
  mode: GameMode;
  genre: SongGenre;
  year_from: number | null;
  year_to: number | null;
  artist_name: string | null;
  songs_per_tier: number;
  guess_timeout_seconds: number;
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
}
