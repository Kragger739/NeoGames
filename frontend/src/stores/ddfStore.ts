import { create } from "zustand";

import { api } from "../lib/api";
import type {
  DdfAnswerMarkedPayload,
  DdfAnswerSubmittedToGmPayload,
  DdfAnswersLockedPayload,
  DdfGameOverPayload,
  DdfGamePausedPayload,
  DdfGameResetPayload,
  DdfGameResumedPayload,
  DdfGameStartedPayload,
  DdfLifeLostPayload,
  DdfPlayerAnsweredPayload,
  DdfPlayerEliminatedPayload,
  DdfPlayersUpdatedPayload,
  DdfQuestionResultPayload,
  DdfQuestionStartedPayload,
  DdfRoundCompletePayload,
  DdfSettingsUpdatedPayload,
  DdfTieNeedsResolutionPayload,
  DdfVoteCastToGmPayload,
  DdfVotingProgressPayload,
  DdfVotingResultsPayload,
  DdfVotingStartedPayload,
} from "../lib/ddfEvents";
import type { DdfCorrectAnswerToGmPayload } from "../lib/ddfEvents";
import { getEcho } from "../lib/echo";
import type {
  DdfCurrentQuestion,
  DdfCycleAnswer,
  DdfGameState,
  DdfGmState,
  DdfLanguage,
  DdfPlayerSummary,
  DdfRoomState,
} from "../lib/ddfTypes";
import type { PresenceMember } from "../lib/roomTypes";
import { useAuthStore } from "./authStore";

export type AnswerStatus = "pending" | "submitted" | "correct" | "wrong";

interface Timer {
  serverTime: string;
  durationSeconds: number;
}

interface GmAnswer {
  roomPlayerId: number;
  nickname: string;
  answerText: string | null;
  submittedAt: string | null;
}

interface GmVote {
  voterRoomPlayerId: number;
  targetRoomPlayerId: number;
}

interface VotingProgress {
  votesCast: number;
  totalEligible: number;
}

interface VotingStarted {
  votingRoundNumber: number;
  isRevote: boolean;
  tieCandidatePlayerIds: number[] | null;
  eligibleTargetIds: number[];
}

interface VotingResults {
  isTie: boolean;
  resolvedBy: "vote" | "revote" | "gm_decision" | null;
  loserRoomPlayerId: number | null;
  tiedPlayerIds: number[];
  awaitingGm: boolean;
  results: Array<{ roomPlayerId: number; voteCount: number }>;
}

interface QuestionResult {
  correctAnswer: string;
  skipped: boolean;
  results: Array<{ roomPlayerId: number; answerText: string | null; isCorrect: boolean | null }>;
}

interface GameOver {
  winnerRoomPlayerId: number | null;
  winnerNickname: string | null;
}

interface DdfState {
  code: string | null;
  hostId: number | null;
  hostName: string | null;
  state: DdfGameState;
  roundsPerVoting: number;
  roundsPlayedThisCycle: number;
  questionTimerSeconds: number;
  votingTimerSeconds: number;
  language: DdfLanguage;
  couchMode: boolean;
  datasetId: number | null;
  datasetName: string | null;
  currentTurnPlayerId: number | null;
  currentQuestion: DdfCurrentQuestion | null;
  isPaused: boolean;
  players: DdfPlayerSummary[];
  members: PresenceMember[];
  timer: Timer | null;
  answerStatus: Record<number, AnswerStatus>;
  votingStarted: VotingStarted | null;
  votingProgress: VotingProgress | null;
  votingResults: VotingResults | null;
  questionResult: QuestionResult | null;
  gameOver: GameOver | null;
  gmAnswers: Record<number, GmAnswer>;
  gmVotes: GmVote[];
  gmTieNeedsResolution: number[] | null;
  safeMode: boolean;
  /** Per-player dot strip: the questions this player was asked this voting cycle, in order. */
  cycleAnswers: Record<number, DdfCycleAnswer[]>;
  /** GM-only: the current question's correct answer, or null between questions. */
  gmCorrectAnswer: string | null;
  channelError: string | null;
  caughtUp: boolean;
  connect: (code: string) => void;
  resync: (code: string) => Promise<void>;
  leaveRoom: () => void;
}

const initialState = {
  code: null,
  hostId: null,
  hostName: null,
  state: "lobby" as DdfGameState,
  roundsPerVoting: 2,
  roundsPlayedThisCycle: 0,
  questionTimerSeconds: 30,
  votingTimerSeconds: 30,
  language: "en" as DdfLanguage,
  couchMode: true,
  datasetId: null as number | null,
  datasetName: null as string | null,
  currentTurnPlayerId: null as number | null,
  currentQuestion: null,
  isPaused: false,
  players: [] as DdfPlayerSummary[],
  members: [] as PresenceMember[],
  timer: null,
  answerStatus: {} as Record<number, AnswerStatus>,
  votingStarted: null,
  votingProgress: null,
  votingResults: null,
  questionResult: null,
  gameOver: null,
  gmAnswers: {} as Record<number, GmAnswer>,
  gmVotes: [] as GmVote[],
  gmTieNeedsResolution: null,
  safeMode: false,
  cycleAnswers: {} as Record<number, DdfCycleAnswer[]>,
  gmCorrectAnswer: null as string | null,
  channelError: null,
  caughtUp: false,
};

/** GET /api/ddf-rooms/{code}/cycle_answers shape -> the store's numeric-keyed camelCase form. */
function mapCycleAnswers(raw: DdfRoomState["cycle_answers"]): Record<number, DdfCycleAnswer[]> {
  const out: Record<number, DdfCycleAnswer[]> = {};
  for (const [id, list] of Object.entries(raw ?? {})) {
    out[Number(id)] = list.map((e) => ({
      questionNumber: e.question_number,
      questionText: e.question_text,
      isCorrect: e.is_correct,
    }));
  }
  return out;
}

/**
 * Pulls the GM-only slice (current question's answer key, this cycle's dot
 * history, every player's submitted text). A player always 403s here - that's
 * fine, it's swallowed; the same doomed-attempt tolerance the private-channel
 * subscription already relies on.
 */
function loadGmState(code: string, set: (partial: Partial<DdfState>) => void): void {
  void api
    .get<DdfGmState>(`/api/ddf-rooms/${code}/gm-state`)
    .then((r) => {
      set({
        gmCorrectAnswer: r.data.correct_answer,
        cycleAnswers: mapCycleAnswers(r.data.cycle_answers),
        gmAnswers: Object.fromEntries(
          r.data.gm_answers.map((a) => [
            a.room_player_id,
            {
              roomPlayerId: a.room_player_id,
              nickname: a.nickname ?? "?",
              answerText: a.answer_text,
              submittedAt: a.submitted_at,
            },
          ]),
        ),
      });
    })
    .catch(() => {});
}

/**
 * The subset of store fields the catch-up GET /api/ddf-rooms/{code} owns -
 * shared by the initial connect() fetch and resync() (used after a GM
 * action whose confirming broadcast might not have landed, e.g. Start).
 */
function roomStateToPatch(room: DdfRoomState) {
  return {
    hostId: room.host_id,
    hostName: room.host_name,
    state: room.state,
    roundsPerVoting: room.rounds_per_voting,
    roundsPlayedThisCycle: room.rounds_played_this_cycle,
    questionTimerSeconds: room.question_timer_seconds,
    votingTimerSeconds: room.voting_timer_seconds,
    language: room.language,
    couchMode: room.couch_mode,
    datasetId: room.dataset_id,
    datasetName: room.dataset_name,
    currentTurnPlayerId: room.current_turn_room_player_id,
    currentQuestion: room.current_question,
    isPaused: room.is_paused,
    players: room.players,
    safeMode: room.safe_mode,
    cycleAnswers: mapCycleAnswers(room.cycle_answers),
  };
}

/**
 * Single owner of both the public room.{code} presence channel and (when
 * this client is a logged-in host) the GM-only private room.{code}.gm
 * channel - mirrors gameStore.ts's centralization reasoning exactly.
 * Server authorizes the private channel by host_id match regardless of
 * what this client believes about itself, so subscribing whenever a host
 * session exists is just an optimization against a doomed attempt for
 * players (who never have one) - a mistaken host's subscription would
 * simply be rejected by the server.
 */
export const useDdfStore = create<DdfState>((set, get) => ({
  ...initialState,

  leaveRoom: () => {
    const code = get().code;
    if (code) {
      getEcho().leave(`room.${code}`);
      getEcho().leave(`room.${code}.gm`);
    }
    set(initialState);
  },

  // Re-pull authoritative room state without touching the channel
  // subscription - used after a GM action (Start especially) so a missed
  // confirming broadcast can't strand the lobby on a stale `state`.
  resync: async (code: string) => {
    const response = await api.get<DdfRoomState>(`/api/ddf-rooms/${code}`);
    set({ ...roomStateToPatch(response.data), caughtUp: true });
    loadGmState(code, set);
  },

  connect: (code: string) => {
    if (get().code === code) return;

    set({ code, state: "lobby", caughtUp: false });

    void api.get<DdfRoomState>(`/api/ddf-rooms/${code}`).then((response) => {
      set({ ...roomStateToPatch(response.data), caughtUp: true });
    });
    loadGmState(code, set);

    const echo = getEcho();
    const channel = echo.join(`room.${code}`);

    channel.here((initialMembers: PresenceMember[]) => {
      set({ members: initialMembers, channelError: null });
    });
    channel.joining((member: PresenceMember) => {
      set((s) => ({ members: [...s.members, member] }));
    });
    channel.leaving((member: PresenceMember) => {
      set((s) => ({ members: s.members.filter((m) => m.id !== member.id) }));
    });
    channel.error(() => {
      set({ channelError: "Couldn't join this room's live channel. You may not have permission." });
    });

    // Fires on join and on any ready-flag toggle - always the full roster,
    // so this simply replaces `players` wholesale rather than patching a
    // delta (handles a brand-new joiner appearing with no separate "player
    // joined" event needed).
    channel.listen(".ddf.players_updated", (payload: DdfPlayersUpdatedPayload) => {
      set({ players: payload.players });
    });

    channel.listen(".ddf.game_started", (payload: DdfGameStartedPayload) => {
      set({
        state: payload.state,
        roundsPerVoting: payload.rounds_per_voting,
        questionTimerSeconds: payload.question_timer_seconds,
        votingTimerSeconds: payload.voting_timer_seconds,
        language: payload.language,
        couchMode: payload.couch_mode,
        timer: { serverTime: payload.server_time, durationSeconds: 3 },
      });
    });

    channel.listen(".ddf.question_started", (payload: DdfQuestionStartedPayload) => {
      set((s) => {
        // rounds_played_this_cycle === 0 marks a new voting cycle's first
        // question - wipe the dot strip before recording this one.
        const base = payload.rounds_played_this_cycle === 0 ? {} : s.cycleAnswers;
        const turnId = payload.current_turn_room_player_id;

        let cycleAnswers = base;
        if (turnId != null) {
          const kept = (base[turnId] ?? []).filter((e) => e.questionNumber !== payload.question_number);
          cycleAnswers = {
            ...base,
            [turnId]: [
              ...kept,
              { questionNumber: payload.question_number, questionText: payload.question_text, isCorrect: null },
            ],
          };
        }

        return {
          state: "question" as DdfGameState,
          currentQuestion: {
            id: payload.question_id,
            text: payload.question_text,
            category: payload.category,
            number: payload.question_number,
          },
          roundsPlayedThisCycle: payload.rounds_played_this_cycle,
          roundsPerVoting: payload.rounds_per_voting,
          timer: { serverTime: payload.server_time, durationSeconds: payload.timer_seconds },
          answerStatus: {},
          questionResult: null,
          gmAnswers: {},
          gmCorrectAnswer: null,
          currentTurnPlayerId: payload.current_turn_room_player_id,
          cycleAnswers,
        };
      });
    });

    channel.listen(".ddf.player_answered", (payload: DdfPlayerAnsweredPayload) => {
      set((s) => ({
        answerStatus: { ...s.answerStatus, [payload.room_player_id]: "submitted" },
      }));
    });

    channel.listen(".ddf.answers_locked", (_payload: DdfAnswersLockedPayload) => {
      set({ state: "answer_submitted", timer: null });
    });

    channel.listen(".ddf.answer_marked", (payload: DdfAnswerMarkedPayload) => {
      set((s) => {
        const qn = s.currentQuestion?.number;
        const forPlayer = s.cycleAnswers[payload.room_player_id];
        const cycleAnswers =
          qn == null || !forPlayer
            ? s.cycleAnswers
            : {
                ...s.cycleAnswers,
                [payload.room_player_id]: forPlayer.map((e) =>
                  e.questionNumber === qn ? { ...e, isCorrect: payload.is_correct } : e,
                ),
              };

        return {
          answerStatus: {
            ...s.answerStatus,
            [payload.room_player_id]: payload.is_correct ? ("correct" as AnswerStatus) : ("wrong" as AnswerStatus),
          },
          cycleAnswers,
        };
      });
    });

    channel.listen(".ddf.question_result", (payload: DdfQuestionResultPayload) => {
      set((s) => {
        const qn = s.currentQuestion?.number;
        let cycleAnswers = s.cycleAnswers;
        if (qn != null) {
          cycleAnswers = { ...s.cycleAnswers };
          for (const r of payload.results) {
            const list = cycleAnswers[r.room_player_id];
            if (list) {
              cycleAnswers[r.room_player_id] = list.map((e) =>
                e.questionNumber === qn ? { ...e, isCorrect: r.is_correct } : e,
              );
            }
          }
        }

        return {
          state: "question_result" as DdfGameState,
          questionResult: {
            correctAnswer: payload.correct_answer,
            skipped: payload.skipped,
            results: payload.results.map((r) => ({
              roomPlayerId: r.room_player_id,
              answerText: r.answer_text,
              isCorrect: r.is_correct,
            })),
          },
          cycleAnswers,
        };
      });
    });

    channel.listen(".ddf.round_complete", (payload: DdfRoundCompletePayload) => {
      set({ state: "round_complete", roundsPerVoting: payload.rounds_per_voting, roundsPlayedThisCycle: 0 });
    });

    channel.listen(".ddf.voting_started", (payload: DdfVotingStartedPayload) => {
      set({
        state: "voting",
        // The auto-voting path skips round_complete, so this is the cycle
        // counter's reset for that path (a revote re-firing it is a no-op).
        roundsPlayedThisCycle: 0,
        timer: { serverTime: payload.server_time, durationSeconds: payload.timer_seconds },
        votingStarted: {
          votingRoundNumber: payload.voting_round_number,
          isRevote: payload.is_revote,
          tieCandidatePlayerIds: payload.tie_candidate_player_ids,
          eligibleTargetIds: payload.eligible_target_ids,
        },
        votingProgress: { votesCast: 0, totalEligible: payload.eligible_voter_ids.length },
        votingResults: null,
        gmVotes: [],
        gmTieNeedsResolution: null,
      });
    });

    channel.listen(".ddf.voting_progress", (payload: DdfVotingProgressPayload) => {
      set({ votingProgress: { votesCast: payload.votes_cast, totalEligible: payload.total_eligible } });
    });

    channel.listen(".ddf.voting_results", (payload: DdfVotingResultsPayload) => {
      set({
        state: "voting_results",
        timer: null,
        votingResults: {
          isTie: payload.is_tie,
          resolvedBy: payload.resolved_by,
          loserRoomPlayerId: payload.loser_room_player_id,
          tiedPlayerIds: payload.tied_player_ids,
          awaitingGm: payload.awaiting_gm,
          results: payload.results.map((r) => ({ roomPlayerId: r.room_player_id, voteCount: r.vote_count })),
        },
      });
    });

    channel.listen(".ddf.life_lost", (payload: DdfLifeLostPayload) => {
      set((s) => ({
        players: s.players.map((p) =>
          p.room_player_id === payload.room_player_id ? { ...p, hearts: payload.hearts_remaining } : p,
        ),
      }));
    });

    channel.listen(".ddf.player_eliminated", (payload: DdfPlayerEliminatedPayload) => {
      set((s) => ({
        players: s.players.map((p) =>
          p.room_player_id === payload.room_player_id ? { ...p, is_eliminated: true } : p,
        ),
      }));
    });

    channel.listen(".ddf.game_paused", (payload: DdfGamePausedPayload) => {
      set({
        isPaused: true,
        timer: { serverTime: payload.server_time, durationSeconds: payload.remaining_seconds },
      });
    });

    channel.listen(".ddf.game_resumed", (payload: DdfGameResumedPayload) => {
      set({
        isPaused: false,
        timer: { serverTime: payload.server_time, durationSeconds: payload.remaining_seconds },
      });
    });

    channel.listen(".ddf.settings_updated", (payload: DdfSettingsUpdatedPayload) => {
      set({
        roundsPerVoting: payload.rounds_per_voting,
        questionTimerSeconds: payload.question_timer_seconds,
        votingTimerSeconds: payload.voting_timer_seconds,
        language: payload.language,
        couchMode: payload.couch_mode,
        safeMode: payload.safe_mode,
        datasetId: payload.dataset_id,
        datasetName: payload.dataset_name,
      });
    });

    channel.listen(".ddf.game_over", (payload: DdfGameOverPayload) => {
      set({
        state: "game_over",
        timer: null,
        gameOver: { winnerRoomPlayerId: payload.winner_room_player_id, winnerNickname: payload.winner_nickname },
        players: payload.players.map((p) => ({
          room_player_id: p.room_player_id,
          nickname: p.nickname,
          hearts: p.hearts,
          is_eliminated: p.is_eliminated,
          is_camera_ready: true,
          level: null,
        })),
      });
    });

    channel.listen(".ddf.game_reset", (payload: DdfGameResetPayload) => {
      set({
        state: "lobby",
        timer: null,
        currentQuestion: null,
        answerStatus: {},
        votingStarted: null,
        votingProgress: null,
        votingResults: null,
        questionResult: null,
        gameOver: null,
        gmAnswers: {},
        gmVotes: [],
        gmTieNeedsResolution: null,
        cycleAnswers: {},
        gmCorrectAnswer: null,
        roundsPlayedThisCycle: 0,
        currentTurnPlayerId: null,
        players: payload.players.map((p) => ({
          room_player_id: p.room_player_id,
          nickname: p.nickname,
          hearts: p.hearts,
          is_eliminated: p.is_eliminated,
          is_camera_ready: false,
          level: null,
        })),
      });
    });

    // GM-only channel - only subscribed when a host session exists. The
    // server is the real authority (rejects a non-matching host outright),
    // this is purely to avoid a doomed subscription attempt for players.
    if (useAuthStore.getState().host) {
      const gmChannel = echo.private(`room.${code}.gm`);

      gmChannel.listen(".ddf.gm.answer_submitted", (payload: DdfAnswerSubmittedToGmPayload) => {
        set((s) => ({
          gmAnswers: {
            ...s.gmAnswers,
            [payload.room_player_id]: {
              roomPlayerId: payload.room_player_id,
              nickname: payload.nickname,
              answerText: payload.answer_text,
              submittedAt: payload.submitted_at,
            },
          },
        }));
      });

      gmChannel.listen(".ddf.gm.vote_cast", (payload: DdfVoteCastToGmPayload) => {
        set((s) => ({
          gmVotes: [
            ...s.gmVotes,
            { voterRoomPlayerId: payload.voter_room_player_id, targetRoomPlayerId: payload.target_room_player_id },
          ],
        }));
      });

      gmChannel.listen(".ddf.gm.tie_needs_resolution", (payload: DdfTieNeedsResolutionPayload) => {
        set({ gmTieNeedsResolution: payload.tied_player_ids });
      });

      gmChannel.listen(".ddf.gm.correct_answer", (payload: DdfCorrectAnswerToGmPayload) => {
        set({ gmCorrectAnswer: payload.correct_answer });
      });
    }
  },
}));
