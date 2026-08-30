import { useEffect, useMemo, useRef, useState } from "react";

import { api } from "../../lib/api";
import { useDdfStore } from "../../stores/ddfStore";
import { DdfAnswerInput } from "./DdfAnswerInput";
import { DdfCountdownTimer } from "./DdfCountdownTimer";
import { DdfEliminationOverlay } from "./DdfEliminationOverlay";
import { DdfGameOverScreen } from "./DdfGameOverScreen";
import { DdfLifeLostAnimation } from "./DdfLifeLostAnimation";
import { DdfQuestionBanner } from "./DdfQuestionBanner";
import { DdfVotingBallot } from "./DdfVotingBallot";
import { DdfVotingResultsChart } from "./DdfVotingResultsChart";
import { DdfWebcamCard } from "./DdfWebcamCard";
import { DdfWebcamGrid } from "./DdfWebcamGrid";

interface DdfGameViewProps {
  code: string;
  viewerId: string | number;
  isGm: boolean;
  localStream: MediaStream | null;
  remoteStreams: Record<string, MediaStream>;
}

/**
 * The shared game-in-progress layout - webcam grid, question banner,
 * countdown, answering UI, voting, and result overlays. Rendered both by
 * DdfPlayOverlayPage (a player, viewerId = their own room_player_id) and
 * DdfGmPanelPage (the Game Master, viewerId = "host") so both roles see the
 * same show, with the GM getting a controls strip above this and a small
 * per-card eliminate button (via onEliminate, only wired when isGm).
 */
export function DdfGameView({ code, viewerId, isGm, localStream, remoteStreams }: DdfGameViewProps) {
  const state = useDdfStore((s) => s.state);
  const hostName = useDdfStore((s) => s.hostName);
  const players = useDdfStore((s) => s.players);
  const currentQuestion = useDdfStore((s) => s.currentQuestion);
  const answerStatus = useDdfStore((s) => s.answerStatus);
  const cycleAnswers = useDdfStore((s) => s.cycleAnswers);
  const timer = useDdfStore((s) => s.timer);
  const isPaused = useDdfStore((s) => s.isPaused);
  const couchMode = useDdfStore((s) => s.couchMode);
  const currentTurnPlayerId = useDdfStore((s) => s.currentTurnPlayerId);
  const votingStarted = useDdfStore((s) => s.votingStarted);
  const votingResults = useDdfStore((s) => s.votingResults);
  const questionResult = useDdfStore((s) => s.questionResult);
  const gameOver = useDdfStore((s) => s.gameOver);

  const [lifeLostFor, setLifeLostFor] = useState<{ nickname: string; hearts: number } | null>(null);
  const [eliminatedNickname, setEliminatedNickname] = useState<string | null>(null);
  const prevPlayerState = useRef<Record<number, { hearts: number; isEliminated: boolean }>>({});

  // Detects a heart drop / elimination from the players list itself
  // (rather than a dedicated store flag) so the animation fires whichever
  // path caused it - a normal vote loss or a GM override - without the
  // store needing a separate transient "just happened" field.
  useEffect(() => {
    for (const p of players) {
      const prior = prevPlayerState.current[p.room_player_id];

      if (prior && p.hearts < prior.hearts) {
        setLifeLostFor({ nickname: p.nickname, hearts: p.hearts });
        window.setTimeout(() => setLifeLostFor(null), 2500);
      }
      if (prior && !prior.isEliminated && p.is_eliminated) {
        setEliminatedNickname(p.nickname);
        window.setTimeout(() => setEliminatedNickname(null), 2500);
      }

      prevPlayerState.current[p.room_player_id] = { hearts: p.hearts, isEliminated: p.is_eliminated };
    }
  }, [players]);

  const gmStream = useMemo(
    () => (isGm ? localStream : (remoteStreams["host"] ?? null)),
    [isGm, localStream, remoteStreams],
  );
  const me = !isGm ? players.find((p) => p.room_player_id === viewerId) : undefined;
  const hasSubmitted = !isGm && viewerId !== null && answerStatus[viewerId as number] !== undefined && answerStatus[viewerId as number] !== "pending";
  const isMyTurn = !isGm && me?.room_player_id === currentTurnPlayerId;
  const turnPlayer = players.find((p) => p.room_player_id === currentTurnPlayerId);

  async function submitAnswer(answerText: string) {
    await api.post(`/api/ddf-rooms/${code}/answer`, { answer_text: answerText });
  }

  async function castVote(targetId: number) {
    await api.post(`/api/ddf-rooms/${code}/vote`, { target_room_player_id: targetId });
  }

  function eliminate(playerId: number) {
    void api.post(`/api/ddf-rooms/${code}/players/${playerId}/eliminate`);
  }

  if (state === "game_over" && gameOver) {
    return (
      <div className="ddf-play-overlay">
        <DdfGameOverScreen winnerNickname={gameOver.winnerNickname} />
      </div>
    );
  }

  return (
    <div className="ddf-play-overlay">
      <div className="ddf-play-gm-tile">
        <DdfWebcamCard name={`👑 ${hostName ?? "Game Master"}`} stream={gmStream} muted={isGm} variant="gm" />
      </div>

      <DdfWebcamGrid>
        {players.map((p) => {
          const isSelf = !isGm && p.room_player_id === viewerId;

          return (
            <DdfWebcamCard
              key={p.room_player_id}
              name={isSelf ? `${p.nickname} (you)` : p.nickname}
              stream={isSelf ? localStream : (remoteStreams[String(p.room_player_id)] ?? null)}
              muted={isSelf}
              hearts={p.hearts}
              isEliminated={p.is_eliminated}
              answerStatus={answerStatus[p.room_player_id]}
              isAnswering={state === "question" && p.room_player_id === currentTurnPlayerId}
              dots={cycleAnswers[p.room_player_id] ?? []}
              onEliminate={isGm && !p.is_eliminated ? () => eliminate(p.room_player_id) : undefined}
            />
          );
        })}
      </DdfWebcamGrid>

      {timer && (state === "question" || state === "voting" || state === "game_start") && (
        <DdfCountdownTimer serverTime={timer.serverTime} durationSeconds={timer.durationSeconds} isPaused={isPaused} />
      )}

      {state === "question" && currentQuestion && (
        <>
          <DdfQuestionBanner category={currentQuestion.category} text={currentQuestion.text} />
          {isMyTurn && !couchMode && me && !me.is_eliminated && (
            <DdfAnswerInput onSubmit={submitAnswer} disabled={hasSubmitted} submitted={hasSubmitted} />
          )}
          {isMyTurn && couchMode && (
            <div className="ddf-turn-status">Answer out loud — the Game Master will mark it.</div>
          )}
          {!isMyTurn && turnPlayer && <div className="ddf-turn-status">It's {turnPlayer.nickname}'s turn.</div>}
        </>
      )}

      {state === "question_result" && questionResult && (
        <div className="ddf-question-result-banner">
          <span className="ddf-question-result-banner-label">✅ CORRECT ANSWER</span>
          <p className="ddf-question-result-banner-text">{questionResult.correctAnswer}</p>
        </div>
      )}

      {state === "voting" && votingStarted && me && !me.is_eliminated && (
        <DdfVotingBallot
          candidates={players.filter(
            (p) =>
              p.room_player_id !== viewerId &&
              !p.is_eliminated &&
              // Server-authoritative target list: already narrowed for safe
              // mode and, on a revote, restricted to the tied candidates.
              votingStarted.eligibleTargetIds.includes(p.room_player_id),
          )}
          onVote={castVote}
          votingRoundNumber={votingStarted.votingRoundNumber}
        />
      )}

      {state === "voting_results" && votingResults && (
        <DdfVotingResultsChart
          results={votingResults.results.map((r) => ({
            roomPlayerId: r.roomPlayerId,
            nickname: players.find((p) => p.room_player_id === r.roomPlayerId)?.nickname ?? "?",
            voteCount: r.voteCount,
          }))}
          loserRoomPlayerId={votingResults.loserRoomPlayerId}
          isTie={votingResults.isTie}
        />
      )}

      {lifeLostFor && <DdfLifeLostAnimation nickname={lifeLostFor.nickname} heartsRemaining={lifeLostFor.hearts} />}
      {eliminatedNickname && <DdfEliminationOverlay nickname={eliminatedNickname} />}
    </div>
  );
}
