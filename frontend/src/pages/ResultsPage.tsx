import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { useAuthStore } from "../stores/authStore";
import { useGameStore } from "../stores/gameStore";

export function ResultsPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();
  const connectGame = useGameStore((state) => state.connect);
  const scoreboard = useGameStore((state) => state.scoreboard);
  const phase = useGameStore((state) => state.phase);
  const caughtUp = useGameStore((state) => state.caughtUp);
  const host = useAuthStore((state) => state.host);
  const fetchHost = useAuthStore((state) => state.fetchHost);
  const authStatus = useAuthStore((state) => state.status);

  const [redoError, setRedoError] = useState<string | null>(null);
  const [redoing, setRedoing] = useState(false);

  useEffect(() => {
    if (authStatus === "idle") {
      void fetchHost();
    }
  }, [authStatus, fetchHost]);

  useEffect(() => {
    if (!code) return;
    connectGame(code);
  }, [code, connectGame]);

  useEffect(() => {
    // A redo sends the room's phase back to "lobby" via the room.reset
    // broadcast - everyone still sitting on this screen follows along.
    // Gated on caughtUp: phase also *starts* as "lobby" before the
    // catch-up fetch resolves, which would otherwise bounce a genuinely
    // finished room's results screen back to the lobby on every load.
    if (phase === "lobby" && caughtUp && code) {
      navigate(`/rooms/${code}/lobby`);
    }
  }, [phase, caughtUp, code, navigate]);

  const isHost = host !== null;

  async function handleRedo() {
    if (!code) return;
    setRedoError(null);
    setRedoing(true);
    try {
      await api.post(`/api/rooms/${code}/redo`);
      // Screen transition happens via the room.reset broadcast, not here.
    } catch (err) {
      setRedoError(firstValidationError(err));
      setRedoing(false);
    }
  }

  return (
    <div className="results-page">
      <h1>Final scoreboard — Room {code?.toUpperCase()}</h1>
      {scoreboard ? (
        <ol className="scoreboard">
          {scoreboard.map((entry) => (
            <li key={entry.id}>
              {entry.nickname} — {entry.score} pts
            </li>
          ))}
        </ol>
      ) : (
        <p>No scoreboard available (did you reach this page directly?)</p>
      )}

      {isHost ? (
        <>
          {redoError && <p className="form-error">{redoError}</p>}
          <button type="button" onClick={handleRedo} disabled={redoing}>
            {redoing ? "Starting over…" : "Redo"}
          </button>
        </>
      ) : (
        <p className="hint">Waiting for the host to start a new round…</p>
      )}
    </div>
  );
}
