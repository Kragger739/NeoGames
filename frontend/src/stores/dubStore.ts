import { create } from "zustand";

import { api } from "../lib/api";
import type {
  DubAssembledPayload,
  DubAssemblyFailedPayload,
  DubAssemblyStartedPayload,
  DubClipSelectedPayload,
  DubGameFinishedPayload,
  DubGameResetPayload,
  DubLinePayload,
  DubNextRoundPayload,
  DubPlayersUpdatedPayload,
  DubRatingProgressPayload,
  DubRatingStartedPayload,
  DubRoleClaimStartedPayload,
  DubRoleClaimUpdatedPayload,
  DubRoundScoredPayload,
  DubTakeRecordedPayload,
} from "../lib/dubEvents";
import { getEcho } from "../lib/echo";
import type {
  DubClip,
  DubGameState,
  DubLine,
  DubPlayerSummary,
  DubRoleAssignment,
  DubRoundSummary,
  DubScoreBreakdownEntry,
  DubRoomState,
  DubTakeSummary,
} from "../lib/dubTypes";
import type { PresenceMember } from "../lib/roomTypes";

interface DubState {
  code: string | null;
  hostId: number | null;
  hostName: string | null;
  playerMode: "solo" | "multiplayer";
  state: DubGameState;
  roundNumber: number;
  totalScore: number;
  lastRoundScore: number | null;
  lineTimerSeconds: number;
  clip: DubClip | null;
  roleAssignments: DubRoleAssignment[];
  players: DubPlayerSummary[];
  members: PresenceMember[];
  currentLineIndex: number;
  currentLine: DubLine | null;
  assignedPlayerId: number | null;
  totalLines: number;
  takes: DubTakeSummary[];
  assembledVideoUrl: string | null;
  assemblyError: string | null;
  ratingCount: number;
  ratingTotal: number;
  myRating: number | null;
  scoreBreakdown: DubScoreBreakdownEntry[];
  finishedRounds: DubRoundSummary[];
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
  playerMode: "multiplayer" as "solo" | "multiplayer",
  state: "lobby" as DubGameState,
  roundNumber: 0,
  totalScore: 0,
  lastRoundScore: null as number | null,
  lineTimerSeconds: 0,
  clip: null as DubClip | null,
  roleAssignments: [] as DubRoleAssignment[],
  players: [] as DubPlayerSummary[],
  members: [] as PresenceMember[],
  currentLineIndex: 0,
  currentLine: null as DubLine | null,
  assignedPlayerId: null as number | null,
  totalLines: 0,
  takes: [] as DubTakeSummary[],
  assembledVideoUrl: null as string | null,
  assemblyError: null as string | null,
  ratingCount: 0,
  ratingTotal: 0,
  myRating: null as number | null,
  scoreBreakdown: [] as DubScoreBreakdownEntry[],
  finishedRounds: [] as DubRoundSummary[],
  channelError: null as string | null,
  caughtUp: false,
};

/** The slice of state the catch-up GET /api/dub-rooms/{code} owns. */
function roomStateToPatch(room: DubRoomState) {
  return {
    hostId: room.host_id,
    hostName: room.host_name,
    playerMode: room.player_mode,
    state: room.state,
    roundNumber: room.round_number,
    totalScore: room.total_score,
    lastRoundScore: room.last_round_score,
    lineTimerSeconds: room.line_timer_seconds,
    clip: room.clip,
    roleAssignments: room.role_assignments,
    players: room.players,
    currentLineIndex: room.current_line_index,
    currentLine: room.current_line,
    assignedPlayerId: room.assigned_room_player_id,
    totalLines: room.total_lines,
    takes: room.takes,
    assembledVideoUrl: room.assembled_video_url,
    assemblyError: room.assembly_error,
    ratingCount: room.rating.count,
    ratingTotal: room.rating.total,
    myRating: room.rating.mine,
  };
}

/**
 * Single owner of the room.{code} presence subscription for "Dub Together"
 * - mirrors ddfStore.ts. There is no host-secret channel: the host is a
 * seated player, so everything rides the one public presence channel.
 */
export const useDubStore = create<DubState>((set, get) => ({
  ...initialState,

  leaveRoom: () => {
    const code = get().code;
    if (code) getEcho().leave(`room.${code}`);
    set(initialState);
  },

  resync: async (code: string) => {
    const response = await api.get<DubRoomState>(`/api/dub-rooms/${code}`);
    set({ ...roomStateToPatch(response.data), caughtUp: true });
  },

  connect: (code: string) => {
    if (get().code === code) return;

    set({ ...initialState, code, caughtUp: false });

    void api.get<DubRoomState>(`/api/dub-rooms/${code}`).then((response) => {
      set({ ...roomStateToPatch(response.data), caughtUp: true });
    });

    const channel = getEcho().join(`room.${code}`);

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

    channel.listen(".dub.players_updated", (p: DubPlayersUpdatedPayload) => {
      set({ players: p.players });
    });

    channel.listen(".dub.clip_selected", (p: DubClipSelectedPayload) => {
      set({ clip: p.clip });
    });

    channel.listen(".dub.role_claim_started", (p: DubRoleClaimStartedPayload) => {
      set({
        state: "role_claim",
        roundNumber: p.round_number,
        clip: p.clip,
        roleAssignments: p.assignments,
        takes: [],
        assembledVideoUrl: null,
        assemblyError: null,
        myRating: null,
        scoreBreakdown: [],
      });
    });

    channel.listen(".dub.next_round", (p: DubNextRoundPayload) => {
      set({
        state: "role_claim",
        roundNumber: p.round_number,
        clip: p.clip,
        roleAssignments: p.assignments,
        takes: [],
        assembledVideoUrl: null,
        assemblyError: null,
        myRating: null,
        lastRoundScore: null,
        scoreBreakdown: [],
      });
    });

    channel.listen(".dub.role_claim_updated", (p: DubRoleClaimUpdatedPayload) => {
      set({ roleAssignments: p.assignments, players: p.players });
    });

    const applyLine = (p: DubLinePayload) =>
      set({
        state: "recording",
        currentLineIndex: p.current_line_index,
        currentLine: p.line,
        assignedPlayerId: p.assigned_room_player_id,
        totalLines: p.total_lines,
        lineTimerSeconds: p.timer_seconds,
      });
    channel.listen(".dub.recording_started", applyLine);
    channel.listen(".dub.line_advanced", applyLine);

    channel.listen(".dub.take_recorded", (p: DubTakeRecordedPayload) => {
      set((s) => ({
        takes: [
          ...s.takes.filter((t) => t.line_id !== p.line_id),
          { line_id: p.line_id, room_player_id: p.room_player_id, take_id: p.take_id, duration_ms: p.duration_ms },
        ],
      }));
    });

    channel.listen(".dub.assembly_started", (_p: DubAssemblyStartedPayload) => {
      set({ state: "assembling", assemblyError: null });
    });

    channel.listen(".dub.assembled", (p: DubAssembledPayload) => {
      set({ state: "watch", assembledVideoUrl: p.assembled_video_url, assemblyError: null });
    });

    channel.listen(".dub.assembly_failed", (p: DubAssemblyFailedPayload) => {
      set({ state: "assembling", assemblyError: p.error });
    });

    channel.listen(".dub.rating_started", (p: DubRatingStartedPayload) => {
      set({ state: "rating", assembledVideoUrl: p.assembled_video_url, ratingCount: 0, myRating: null });
    });

    channel.listen(".dub.rating_progress", (p: DubRatingProgressPayload) => {
      set({ ratingCount: p.count, ratingTotal: p.total });
    });

    channel.listen(".dub.round_scored", (p: DubRoundScoredPayload) => {
      set({
        state: "round_complete",
        lastRoundScore: p.last_round_score,
        totalScore: p.total_score,
        scoreBreakdown: p.breakdown,
      });
    });

    channel.listen(".dub.game_finished", (p: DubGameFinishedPayload) => {
      set({ state: "finished", totalScore: p.total_score, finishedRounds: p.rounds });
    });

    channel.listen(".dub.game_reset", (p: DubGameResetPayload) => {
      set({
        ...initialState,
        code: get().code,
        hostId: get().hostId,
        hostName: get().hostName,
        playerMode: get().playerMode,
        members: get().members,
        players: p.players,
        caughtUp: true,
      });
    });
  },
}));

export type { DubClip };
