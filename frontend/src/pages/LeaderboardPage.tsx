import { useEffect } from "react";
import { Link } from "react-router-dom";

import { EMPTY_AVATAR } from "../lib/avatarData";
import { useLeaderboardStore } from "../stores/leaderboardStore";
import { Avatar } from "../components/ui/Avatar";
import { Badge } from "../components/ui/Badge";

const MEDAL = ["gold", "silver", "bronze"] as const;

function countdown(endsAt: string): string {
  const ms = new Date(endsAt).getTime() - Date.now();
  if (ms <= 0) return "season over";
  const days = Math.floor(ms / 86_400_000);
  if (days >= 1) return `${days}d left`;
  return `${Math.max(1, Math.floor(ms / 3_600_000))}h left`;
}

export function LeaderboardPage() {
  const status = useLeaderboardStore((state) => state.status);
  const data = useLeaderboardStore((state) => state.data);
  const fetch = useLeaderboardStore((state) => state.fetch);

  useEffect(() => {
    void fetch();
  }, [fetch]);

  const meInList =
    data?.me != null && data.entries.some((entry) => entry.rank === data.me!.rank);

  return (
    <div className="leaderboard-page">
      <p>
        <Link to="/">← Home</Link>
      </p>
      <h1>Leaderboard</h1>

      {status !== "ready" ? (
        <p className="hint">Loading…</p>
      ) : !data?.season ? (
        <p className="hint">No season is running right now.</p>
      ) : (
        <>
          <p className="hint">
            {data.season.name} · {countdown(data.season.ends_at)}
          </p>

          {data.entries.length === 0 ? (
            <p className="hint">Nobody&rsquo;s on the board yet — play a game to claim the top spot.</p>
          ) : (
            <ol className="player-list leaderboard-list">
              {data.entries.map((entry) => (
                <li key={entry.rank}>
                  <span className="friend-name">
                    {entry.rank <= 3 ? (
                      <Badge tone={MEDAL[entry.rank - 1]}>{entry.rank}</Badge>
                    ) : (
                      <span className="leaderboard-rank">{entry.rank}</span>
                    )}
                    <Avatar data={entry.avatar ?? EMPTY_AVATAR} size="sm" animated={false} />
                    {entry.username}
                  </span>
                  <span className="leaderboard-xp">{entry.season_xp.toLocaleString()} XP</span>
                </li>
              ))}
            </ol>
          )}

          {data.me != null && !meInList && (
            <p className="leaderboard-me">
              Your rank: <strong>#{data.me.rank}</strong> · {data.me.season_xp.toLocaleString()} XP
            </p>
          )}
        </>
      )}
    </div>
  );
}
