import { useEffect } from "react";
import { useNavigate, useParams } from "react-router-dom";

import { api } from "../lib/api";
import { useWebcamMesh } from "../lib/webrtc";
import { useDdfStore } from "../stores/ddfStore";
import { Button } from "../components/ui/Button";
import { AvConsentGate } from "../components/ddf/AvConsentGate";
import { DdfGameView } from "../components/ddf/DdfGameView";
import { DdfGmAnswerKey } from "../components/ddf/gm/DdfGmAnswerKey";
import { DdfGmControlBar } from "../components/ddf/gm/DdfGmControlBar";
import { DdfGmVoteTally } from "../components/ddf/gm/DdfGmVoteTally";

/** Same show the players see (via the shared DdfGameView), with GM controls in a strip on top. */
export function DdfGmPanelPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();

  const connect = useDdfStore((s) => s.connect);
  const leaveRoom = useDdfStore((s) => s.leaveRoom);
  const state = useDdfStore((s) => s.state);
  const players = useDdfStore((s) => s.players);
  const members = useDdfStore((s) => s.members);
  const answerStatus = useDdfStore((s) => s.answerStatus);
  const isPaused = useDdfStore((s) => s.isPaused);
  const roundsPerVoting = useDdfStore((s) => s.roundsPerVoting);
  const cycleAnswers = useDdfStore((s) => s.cycleAnswers);
  const couchMode = useDdfStore((s) => s.couchMode);
  const currentTurnPlayerId = useDdfStore((s) => s.currentTurnPlayerId);
  const votingProgress = useDdfStore((s) => s.votingProgress);
  const gmVotes = useDdfStore((s) => s.gmVotes);
  const gmTieNeedsResolution = useDdfStore((s) => s.gmTieNeedsResolution);
  const gmCorrectAnswer = useDdfStore((s) => s.gmCorrectAnswer);
  const gmAnswers = useDdfStore((s) => s.gmAnswers);
  const caughtUp = useDdfStore((s) => s.caughtUp);

  const { localStream, remoteStreams } = useWebcamMesh(code ?? null, "host", members);

  useEffect(() => {
    if (!code) return;
    connect(code);
    return () => leaveRoom();
  }, [code, connect, leaveRoom]);

  useEffect(() => {
    if (caughtUp && state === "lobby") {
      navigate(`/ddf-rooms/${code}/lobby`);
    }
  }, [caughtUp, state, code, navigate]);

  if (!code) return null;

  const isVoting = state === "voting" || state === "voting_results";
  const canMark = state === "question" || state === "answer_submitted";
  const turnPlayer = players.find((p) => p.room_player_id === currentTurnPlayerId);
  const turnAnswerStatus = currentTurnPlayerId !== null ? answerStatus[currentTurnPlayerId] : undefined;
  const alreadyMarked = turnAnswerStatus === "correct" || turnAnswerStatus === "wrong";
  const canMarkYet = couchMode ? state === "question" || state === "answer_submitted" : state === "answer_submitted";

  // Mirrors the server rule (DdfGameService::everyoneHadTheirTurns): voting
  // opens once every active player has answered rounds_per_voting questions
  // this cycle. At question_result the just-finished question is already in
  // cycleAnswers, so this tells us the next advance opens voting.
  const activePlayers = players.filter((p) => !p.is_eliminated);
  const cycleWillVoteNext =
    activePlayers.length > 0 &&
    activePlayers.every((p) => (cycleAnswers[p.room_player_id]?.length ?? 0) >= roundsPerVoting);

  function mark(isCorrect: boolean) {
    if (!turnPlayer) return;
    void api.post(`/api/ddf-rooms/${code}/players/${turnPlayer.room_player_id}/mark`, { is_correct: isCorrect });
  }

  return (
    <AvConsentGate>
    <div className="ddf-gm-page">
      <header className="ddf-gm-controls-strip">
        <DdfGmAnswerKey
          correctAnswer={gmCorrectAnswer}
          answers={Object.values(gmAnswers).map((a) => ({
            roomPlayerId: a.roomPlayerId,
            nickname: a.nickname,
            answerText: a.answerText,
          }))}
          turnNickname={turnPlayer?.nickname ?? null}
          visible={state === "question" || state === "answer_submitted"}
        />

        {canMark && turnPlayer && (
          <div className="ddf-gm-mark-strip">
            <span>{turnPlayer.nickname}</span>
            <Button
              variant={turnAnswerStatus === "correct" ? "turquoise" : "ghost"}
              disabled={alreadyMarked || !canMarkYet}
              onClick={() => mark(true)}
            >
              ✅
            </Button>
            <Button
              variant={turnAnswerStatus === "wrong" ? "danger" : "ghost"}
              disabled={alreadyMarked || !canMarkYet}
              onClick={() => mark(false)}
            >
              ❌
            </Button>
          </div>
        )}

        {isVoting && (
          <DdfGmVoteTally
            players={players}
            votes={gmVotes}
            votesCast={votingProgress?.votesCast ?? 0}
            totalEligible={votingProgress?.totalEligible ?? 0}
            tieCandidateIds={gmTieNeedsResolution}
            onResolveTie={(loserId) =>
              void api.post(`/api/ddf-rooms/${code}/resolve-tie`, { loser_room_player_id: loserId })
            }
          />
        )}

        <DdfGmControlBar
          state={state}
          isPaused={isPaused}
          cycleWillVoteNext={cycleWillVoteNext}
          couchMode={couchMode}
          turnMarked={alreadyMarked}
          awaitingTie={gmTieNeedsResolution !== null}
          onPause={() => void api.post(`/api/ddf-rooms/${code}/pause`)}
          onResume={() => void api.post(`/api/ddf-rooms/${code}/resume`)}
          onNextQuestion={() => void api.post(`/api/ddf-rooms/${code}/next-question`)}
          onSkipQuestion={() => void api.post(`/api/ddf-rooms/${code}/skip-question`)}
          onStartVoting={() => void api.post(`/api/ddf-rooms/${code}/start-voting`)}
          onEndVoting={() => void api.post(`/api/ddf-rooms/${code}/end-voting`)}
          onRestart={() => void api.post(`/api/ddf-rooms/${code}/restart`)}
          onEnd={() => void api.post(`/api/ddf-rooms/${code}/end`)}
        />
      </header>

      <DdfGameView code={code} viewerId="host" isGm={true} localStream={localStream} remoteStreams={remoteStreams} />
    </div>
    </AvConsentGate>
  );
}
