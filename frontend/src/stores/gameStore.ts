import { create } from "zustand";

import { api } from "../lib/api";
import { getEcho } from "../lib/echo";
import type {
  BattleRoyalePlayer,
  BattleRoyaleRoundResolvedPayload,
  GameFinishedPayload,
  GuessMissedPayload,
  RevealedAnswer,
  RoomResetPayload,
  RoomSettingsUpdatedPayload,
  RoundFailedPayload,
  RoundStageAdvancedPayload,
  RoundStartedPayload,
  RoundWonPayload,
  ScoreboardEntry,
  TierAdvancedPayload,
} from "../lib/gameEvents";
import type { GameMode, PresenceMember, RoomState, SongGenre } from "../lib/roomTypes";

type Phase = "lobby" | "playing" | "revealed" | "finished";

export interface Outcome {
  type: "won" | "failed" | "battle_royale";
  answer: RevealedAnswer;
  winnerNickname?: string; // "won" only
  points?: number; // "won" only
  survivors?: BattleRoyalePlayer[]; // "battle_royale" only
  eliminated?: BattleRoyalePlayer[]; // "battle_royale" only
}

interface GameState {
  phase: Phase;
  code: string | null;
  round: RoundStartedPayload | null;
  tier: string | null;
  outcome: Outcome | null;
  missedNotices: string[];
  scoreboard: ScoreboardEntry[] | null;
  players: ScoreboardEntry[];
  members: PresenceMember[];
  channelError: string | null;
  guessTimeoutSeconds: number | null;
  songsPerTier: number | null;
  mode: GameMode | null;
  genre: SongGenre | null;
  yearFrom: number | null;
  yearTo: number | null;
  artistName: string | null;
  // False until the catch-up GET resolves at least once. `phase` defaults
  // to "lobby" before that, which is indistinguishable from a genuine
  // room.reset - consumers that navigate off of phase === "lobby" (e.g.
  // ResultsPage) must also check this, or a finished room briefly looks
  // reset before its real status has loaded.
  caughtUp: boolean;
  connect: (code: string) => void;
}

/**
 * Single owner of the room's Echo channel subscription. Centralized here
 * (rather than split across Lobby/Play pages) so React StrictMode's
 * mount->cleanup->mount dev-mode double-invoke can't tear down the
 * channel out from under a listener bound by a different component's
 * effect - the two independent join/leave cycles were racing.
 */
export const useGameStore = create<GameState>((set, get) => ({
  phase: "lobby",
  code: null,
  round: null,
  tier: null,
  outcome: null,
  missedNotices: [],
  scoreboard: null,
  players: [],
  members: [],
  channelError: null,
  guessTimeoutSeconds: null,
  songsPerTier: null,
  mode: null,
  genre: null,
  yearFrom: null,
  yearTo: null,
  artistName: null,
  caughtUp: false,

  connect: (code: string) => {
    if (get().code === code) return;

    set({ code, phase: "lobby", caughtUp: false });

    // Catch-up fetch for a reconnecting/refreshing client: only applied if
    // no broadcast has already moved the phase past "lobby" by the time it
    // resolves, so a live event never gets clobbered by a stale response.
    void api.get<RoomState>(`/api/rooms/${code}`).then((response) => {
      const state = response.data;
      set({
        players: state.players,
        guessTimeoutSeconds: state.guess_timeout_seconds,
        songsPerTier: state.songs_per_tier,
        mode: state.mode,
        genre: state.genre,
        yearFrom: state.year_from,
        yearTo: state.year_to,
        artistName: state.artist_name,
      });

      if (get().phase !== "lobby") return;

      if (state.status === "active" && state.current_round) {
        set({
          phase: "playing",
          round: state.current_round,
          tier: state.current_round.tier,
        });
      } else if (state.status === "finished") {
        set({ phase: "finished", scoreboard: state.players });
      }

      set({ caughtUp: true });
    });

    const echo = getEcho();
    const channel = echo.join(`room.${code}`);

    channel.here((initialMembers: PresenceMember[]) => {
      set({ members: initialMembers, channelError: null });
    });
    channel.joining((member: PresenceMember) => {
      set((state) => ({ members: [...state.members, member] }));
    });
    channel.leaving((member: PresenceMember) => {
      set((state) => ({
        members: state.members.filter((m) => m.id !== member.id),
      }));
    });
    channel.error(() => {
      set({
        channelError:
          "Couldn't join this room's live channel. You may not have permission.",
      });
    });

    channel.listen(".round.started", (payload: RoundStartedPayload) => {
      set({
        phase: "playing",
        round: payload,
        tier: payload.tier,
        outcome: null,
        missedNotices: [],
      });
    });

    channel.listen(".round.stage_advanced", (payload: RoundStageAdvancedPayload) => {
      set({ round: payload, tier: payload.tier });
    });

    channel.listen(".round.won", (payload: RoundWonPayload) => {
      set({
        phase: "revealed",
        outcome: {
          type: "won",
          answer: payload.answer,
          winnerNickname: payload.winner_nickname,
          points: payload.points,
        },
        players: payload.scoreboard,
      });
    });

    channel.listen(".round.failed", (payload: RoundFailedPayload) => {
      set({
        phase: "revealed",
        outcome: { type: "failed", answer: payload.answer },
      });
    });

    // Battle Royale's round-close signal - never fires for Classic/Solo
    // rounds, which keep using .round.won/.round.failed above unchanged.
    channel.listen(".round.br_resolved", (payload: BattleRoyaleRoundResolvedPayload) => {
      set({
        phase: "revealed",
        outcome: {
          type: "battle_royale",
          answer: payload.answer,
          survivors: payload.survivors,
          eliminated: payload.eliminated,
        },
        players: payload.scoreboard,
      });
    });

    channel.listen(".round.guess_missed", (payload: GuessMissedPayload) => {
      set((state) => ({
        missedNotices: [payload.nickname, ...state.missedNotices].slice(0, 5),
      }));
    });

    channel.listen(".tier.advanced", (payload: TierAdvancedPayload) => {
      set({ tier: payload.tier });
    });

    channel.listen(".game.finished", (payload: GameFinishedPayload) => {
      set({ phase: "finished", scoreboard: payload.scoreboard });
    });

    channel.listen(".room.reset", (payload: RoomResetPayload) => {
      set({
        phase: "lobby",
        round: null,
        tier: null,
        outcome: null,
        missedNotices: [],
        scoreboard: null,
        players: payload.players,
      });
    });

    channel.listen(".room.settings_updated", (payload: RoomSettingsUpdatedPayload) => {
      set({
        songsPerTier: payload.songs_per_tier,
        guessTimeoutSeconds: payload.guess_timeout_seconds,
        mode: payload.mode as GameMode,
        genre: payload.genre as SongGenre,
        yearFrom: payload.year_from,
        yearTo: payload.year_to,
        artistName: payload.artist_name,
      });
    });
  },
}));
