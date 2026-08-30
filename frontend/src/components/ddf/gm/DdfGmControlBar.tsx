import { Button } from "../../ui/Button";
import type { DdfGameState } from "../../../lib/ddfTypes";

interface DdfGmControlBarProps {
  state: DdfGameState;
  isPaused: boolean;
  /** Every active player has now had their configured number of turns - the next advance opens voting. */
  cycleWillVoteNext: boolean;
  couchMode: boolean;
  /** The current turn player's answer has been graded ✅/❌. */
  turnMarked: boolean;
  /** VotingResults is blocked on a still-tied GM decision. */
  awaitingTie: boolean;
  onPause: () => void;
  onResume: () => void;
  onNextQuestion: () => void;
  onSkipQuestion: () => void;
  onStartVoting: () => void;
  onEndVoting: () => void;
  onRestart: () => void;
  onEnd: () => void;
}

/**
 * Resolves the one primary "Advance" button for the current state - label,
 * handler, and whether it's usable. `null` handler => the phase advances on
 * its own (a timer, or the vote resolving) and the button is a disabled hint.
 */
function advanceFor(props: DdfGmControlBarProps): { label: string; onClick: (() => void) | null } {
  const { state, cycleWillVoteNext, couchMode, turnMarked, awaitingTie } = props;

  switch (state) {
    case "game_start":
      return { label: "Starting…", onClick: null };
    case "question":
      return couchMode && !turnMarked
        ? { label: "Waiting for your mark", onClick: null }
        : { label: couchMode ? "Reveal result" : "Reveal answer", onClick: props.onSkipQuestion };
    case "answer_submitted":
      return turnMarked
        ? { label: "Reveal result", onClick: props.onSkipQuestion }
        : { label: "Mark the answer first", onClick: null };
    case "question_result":
      return cycleWillVoteNext
        ? { label: "Start voting", onClick: props.onNextQuestion }
        : { label: "Next question", onClick: props.onNextQuestion };
    case "round_complete":
      return { label: "Next question", onClick: props.onNextQuestion };
    case "voting":
      return { label: "End voting now", onClick: props.onEndVoting };
    case "voting_results":
      return awaitingTie
        ? { label: "Resolve the tie above", onClick: null }
        : { label: "Advancing…", onClick: null };
    case "game_over":
      return { label: "Restart", onClick: props.onRestart };
    default:
      return { label: "Advance", onClick: null };
  }
}

/** The GM's primary flow-control button plus the secondary context actions. */
export function DdfGmControlBar(props: DdfGmControlBarProps) {
  const { state, isPaused, onPause, onResume, onNextQuestion, onSkipQuestion, onStartVoting, onEndVoting, onRestart, onEnd } =
    props;

  const advance = advanceFor(props);

  const canPause = state === "question" || state === "voting";
  const canNextQuestion = state === "question_result" || state === "round_complete";
  const canSkip = state === "question" || state === "answer_submitted";
  const canStartVoting = state === "round_complete" || state === "question_result" || state === "question";
  const canEndVoting = state === "voting";

  return (
    <div className="ddf-gm-control-bar">
      <Button
        variant="grape"
        className="ddf-gm-advance-btn"
        disabled={advance.onClick === null}
        onClick={() => advance.onClick?.()}
      >
        {advance.label} ▶
      </Button>

      {canPause && (
        <Button variant="ghost" onClick={isPaused ? onResume : onPause}>
          {isPaused ? "Resume" : "Pause"}
        </Button>
      )}
      {canNextQuestion && (
        <Button variant="ghost" onClick={onNextQuestion}>
          Next question
        </Button>
      )}
      {canSkip && (
        <Button variant="ghost" onClick={onSkipQuestion}>
          Skip question
        </Button>
      )}
      {canStartVoting && (
        <Button variant="ghost" onClick={onStartVoting}>
          Start voting
        </Button>
      )}
      {canEndVoting && (
        <Button variant="ghost" onClick={onEndVoting}>
          End voting now
        </Button>
      )}
      <Button variant="ghost" onClick={onRestart}>
        Restart
      </Button>
      <Button variant="danger" onClick={onEnd}>
        End game
      </Button>
    </div>
  );
}
