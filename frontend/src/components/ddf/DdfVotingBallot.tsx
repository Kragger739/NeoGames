import { useState } from "react";

import { Button } from "../ui/Button";
import type { DdfPlayerSummary } from "../../lib/ddfTypes";

interface DdfVotingBallotProps {
  candidates: DdfPlayerSummary[];
  onVote: (targetRoomPlayerId: number) => Promise<void>;
  /** Resets to false whenever a NEW voting phase starts - pass votingRoundNumber as part of `key` from the caller. */
  votingRoundNumber: number;
}

/** "Who gave the dumbest answer?" - anonymous, one pick, no self-vote. */
export function DdfVotingBallot({ candidates, onVote, votingRoundNumber }: DdfVotingBallotProps) {
  const [selected, setSelected] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [hasVoted, setHasVoted] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [votedInRound, setVotedInRound] = useState<number | null>(null);

  // A fresh voting phase (including an automatic revote) must re-enable
  // the ballot even though this component instance stays mounted.
  if (votedInRound !== null && votedInRound !== votingRoundNumber && hasVoted) {
    setHasVoted(false);
    setSelected(null);
  }

  async function castVote(targetId: number) {
    setSelected(targetId);
    setSubmitting(true);
    setError(null);
    try {
      await onVote(targetId);
      setHasVoted(true);
      setVotedInRound(votingRoundNumber);
    } catch {
      setError("Couldn't cast your vote - try again.");
      setSelected(null);
    } finally {
      setSubmitting(false);
    }
  }

  if (hasVoted) {
    return (
      <div className="ddf-voting-ballot">
        <h2>🗳️ WHO GAVE THE DUMBEST ANSWER?</h2>
        <p className="ddf-voting-waiting">Vote cast — waiting for the others…</p>
      </div>
    );
  }

  return (
    <div className="ddf-voting-ballot">
      <h2>🗳️ WHO GAVE THE DUMBEST ANSWER?</h2>
      <div className="ddf-voting-candidates">
        {candidates.map((c) => (
          <Button
            key={c.room_player_id}
            variant={selected === c.room_player_id ? "grape" : "ghost"}
            disabled={submitting}
            onClick={() => void castVote(c.room_player_id)}
          >
            {c.nickname}
          </Button>
        ))}
      </div>
      {error && <p className="form-error">{error}</p>}
    </div>
  );
}
