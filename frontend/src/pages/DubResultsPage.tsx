import { useEffect } from "react";
import { useNavigate, useParams } from "react-router-dom";

import { dubPathForState } from "../lib/dubNav";
import { leaveRoomOnServer } from "../lib/leaveRoom";
import { useLeaveRoomOnClose } from "../hooks/useLeaveRoomOnClose";
import { useDubStore } from "../stores/dubStore";
import { Button } from "../components/ui/Button";

export function DubResultsPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();

  const connect = useDubStore((s) => s.connect);
  const leaveRoom = useDubStore((s) => s.leaveRoom);
  const state = useDubStore((s) => s.state);
  const playerMode = useDubStore((s) => s.playerMode);
  const totalScore = useDubStore((s) => s.totalScore);
  const finishedRounds = useDubStore((s) => s.finishedRounds);
  const caughtUp = useDubStore((s) => s.caughtUp);

  const solo = playerMode === "solo";

  useLeaveRoomOnClose(code);

  useEffect(() => {
    if (code) connect(code);
  }, [code, connect]);

  useEffect(() => {
    if (caughtUp && code && state !== "finished") {
      navigate(dubPathForState(code, state));
    }
  }, [caughtUp, state, code, navigate]);

  async function handleLeave() {
    if (code) await leaveRoomOnServer(code);
    leaveRoom();
    navigate("/");
  }

  return (
    <div className="dub-results-page">
      <h1>That's a wrap!</h1>
      {solo ? (
        <p className="hint">Your dub{finishedRounds.length === 1 ? "" : "s"} — grab {finishedRounds.length === 1 ? "it" : "them"} below.</p>
      ) : (
        <>
          <p className="dub-results-total">{totalScore.toFixed(2)}</p>
          <p className="hint">Combined co-op score across {finishedRounds.length || "all"} rounds</p>
        </>
      )}

      <ul className="dub-results-rounds">
        {finishedRounds.map((r) => (
          <li key={r.round_number}>
            <div>
              <strong>Round {r.round_number}</strong>
              <span className="hint"> {r.clip_title}</span>
            </div>
            <div className="dub-results-round-meta">
              {!solo && <span>{r.score != null ? `${r.score.toFixed(2)}★` : "—"}</span>}
              {r.video_url && (
                <a className="btn btn-ghost" href={r.video_url} download={`dub-round-${r.round_number}.mp4`}>
                  Download
                </a>
              )}
            </div>
          </li>
        ))}
      </ul>

      <Button variant="grape" size="lg" onClick={() => void handleLeave()}>
        Leave
      </Button>
    </div>
  );
}
