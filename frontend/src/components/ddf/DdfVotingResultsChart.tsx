interface ResultRow {
  roomPlayerId: number;
  nickname: string;
  voteCount: number;
}

interface DdfVotingResultsChartProps {
  results: ResultRow[];
  loserRoomPlayerId: number | null;
  isTie: boolean;
}

/** 🗳️ VOTING RESULTS — a horizontal bar per player, most votes on top. */
export function DdfVotingResultsChart({ results, loserRoomPlayerId, isTie }: DdfVotingResultsChartProps) {
  const maxVotes = Math.max(1, ...results.map((r) => r.voteCount));
  const sorted = [...results].sort((a, b) => b.voteCount - a.voteCount);
  const loser = sorted.find((r) => r.roomPlayerId === loserRoomPlayerId);

  return (
    <div className="ddf-voting-results">
      <h2>🗳️ VOTING RESULTS</h2>
      <div className="ddf-voting-results-bars">
        {sorted.map((r) => (
          <div key={r.roomPlayerId} className="ddf-voting-results-row">
            <span className="ddf-voting-results-name">{r.nickname}</span>
            <div className="ddf-voting-results-track">
              <div
                className={
                  r.roomPlayerId === loserRoomPlayerId
                    ? "ddf-voting-results-fill ddf-voting-results-fill-loser"
                    : "ddf-voting-results-fill"
                }
                style={{ transform: `scaleX(${r.voteCount / maxVotes})` }}
              />
            </div>
            <span className="ddf-voting-results-count">{r.voteCount}</span>
          </div>
        ))}
      </div>
      {isTie ? (
        <p className="ddf-voting-results-banner">TIE — REVOTE!</p>
      ) : (
        loser && <p className="ddf-voting-results-banner">{loser.nickname.toUpperCase()} LOSES A LIFE!</p>
      )}
    </div>
  );
}
