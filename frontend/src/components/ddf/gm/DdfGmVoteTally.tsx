import { Button } from "../../ui/Button";
import type { DdfPlayerSummary } from "../../../lib/ddfTypes";

interface GmVote {
  voterRoomPlayerId: number;
  targetRoomPlayerId: number;
}

interface DdfGmVoteTallyProps {
  players: DdfPlayerSummary[];
  votes: GmVote[];
  votesCast: number;
  totalEligible: number;
  tieCandidateIds: number[] | null;
  onResolveTie?: (loserRoomPlayerId: number) => void;
}

/** Live "who's voted for whom" - visible only to the GM, before the public reveal. */
export function DdfGmVoteTally({
  players,
  votes,
  votesCast,
  totalEligible,
  tieCandidateIds,
  onResolveTie,
}: DdfGmVoteTallyProps) {
  const nameOf = (id: number) => players.find((p) => p.room_player_id === id)?.nickname ?? `#${id}`;

  return (
    <div className="ddf-gm-vote-tally">
      <p className="ddf-gm-vote-progress">
        {votesCast} / {totalEligible} voted
      </p>
      <ul className="ddf-gm-vote-list">
        {votes.map((v, i) => (
          <li key={i}>
            {nameOf(v.voterRoomPlayerId)} → {nameOf(v.targetRoomPlayerId)}
          </li>
        ))}
      </ul>
      {tieCandidateIds && onResolveTie && (
        <div className="ddf-gm-tie-resolution">
          <p>Still tied — pick who loses a life:</p>
          <div className="ddf-gm-tie-buttons">
            {tieCandidateIds.map((id) => (
              <Button key={id} variant="danger" onClick={() => onResolveTie(id)}>
                {nameOf(id)}
              </Button>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
