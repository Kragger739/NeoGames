import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { LogOut, RotateCcw } from "lucide-react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { leaveRoomOnServer } from "../lib/leaveRoom";
import { EMPTY_AVATAR } from "../lib/avatarData";
import type { SongHistoryEntry, SongHistoryResponse } from "../lib/roomTypes";
import { useAuthStore } from "../stores/authStore";
import { useGameStore } from "../stores/gameStore";
import { Avatar } from "../components/ui/Avatar";
import { Button } from "../components/ui/Button";
import { IconButton } from "../components/ui/IconButton";
import { Podium } from "../components/illustrations/Podium";

export function ResultsPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();
  const connectGame = useGameStore((state) => state.connect);
  const leaveRoom = useGameStore((state) => state.leaveRoom);
  const scoreboard = useGameStore((state) => state.scoreboard);
  const phase = useGameStore((state) => state.phase);
  const caughtUp = useGameStore((state) => state.caughtUp);
  const hostId = useGameStore((state) => state.hostId);
  const host = useAuthStore((state) => state.host);
  const fetchHost = useAuthStore((state) => state.fetchHost);
  const authStatus = useAuthStore((state) => state.status);

  const [redoError, setRedoError] = useState<string | null>(null);
  const [redoing, setRedoing] = useState(false);
  const [songHistory, setSongHistory] = useState<SongHistoryEntry[] | null>(null);

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
    if (!code) return;
    void api
      .get<SongHistoryResponse>(`/api/rooms/${code}/song-history`)
      .then((response) => setSongHistory(response.data.rounds));
  }, [code]);

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

  const isHost = host !== null && hostId !== null && host.id === hostId;

  async function handleLeave() {
    if (code) await leaveRoomOnServer(code);
    leaveRoom();
    navigate("/");
  }

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

  const podium = scoreboard?.slice(0, 3) ?? [];
  const rest = scoreboard?.slice(3) ?? [];

  return (
    <div className="results-page">
      <div className="podium-group">
        <Podium className="results-hero" />
        {podium.length > 0 && (
          <div className="podium-names">
            {[1, 0, 2].map((idx) => (
              <span key={idx} className={`podium-name podium-name-${idx === 0 ? 1 : idx === 1 ? 2 : 3}`}>
                {podium[idx] && (
                  <>
                    <Avatar data={podium[idx].avatar ?? EMPTY_AVATAR} size="sm" animated={false} />
                    {podium[idx].nickname}
                  </>
                )}
              </span>
            ))}
          </div>
        )}
      </div>
      <h1>That's a wrap!</h1>
      <p className="hint">Room {code?.toUpperCase()}</p>
      {scoreboard ? (
        rest.length > 0 && (
          <ol className="scoreboard">
            {rest.map((entry) => (
              <li key={entry.id}>
                <span className="friend-name">
                  <Avatar data={entry.avatar ?? EMPTY_AVATAR} size="xs" animated={false} />
                  {entry.nickname}
                  {entry.level !== null && <span className="player-level">Lvl {entry.level}</span>}
                </span>
                <span>{entry.score} pts</span>
              </li>
            ))}
          </ol>
        )
      ) : (
        <p>No scoreboard available (did you reach this page directly?)</p>
      )}

      {songHistory && songHistory.length > 0 && (
        <>
          <h2>Songs this game</h2>
          <ol className="song-history">
            {songHistory.map((entry) => (
              <li key={entry.round_id}>
                {entry.song.album_art_url ? (
                  <img
                    className="song-history-art"
                    src={entry.song.album_art_url}
                    alt=""
                    width={40}
                    height={40}
                  />
                ) : (
                  <span className="song-history-art art-placeholder" aria-hidden="true" />
                )}
                <span className="song-history-info">
                  <span className="song-history-title">{entry.song.title}</span>
                  <span className="hint">{entry.song.artist}</span>
                </span>
                <span className="song-history-guessers">
                  {entry.guessers.length > 0 ? (
                    entry.guessers.map((guesser, i) => (
                      <span key={i} className="song-history-guesser">
                        {guesser.nickname}
                        <span className="song-history-stage">{guesser.snippet_stage}s</span>
                      </span>
                    ))
                  ) : (
                    <span className="hint">Nobody guessed it</span>
                  )}
                </span>
              </li>
            ))}
          </ol>
        </>
      )}

      {isHost ? (
        <>
          {redoError && <p className="form-error">{redoError}</p>}
          <Button size="lg" onClick={handleRedo} disabled={redoing}>
            {redoing ? (
              "Starting over…"
            ) : (
              <>
                <RotateCcw size={20} strokeWidth={2.5} />
                Play again
              </>
            )}
          </Button>
        </>
      ) : (
        <p className="hint">Waiting for the host to start a new round…</p>
      )}
      <IconButton icon={LogOut} label="Leave room" className="results-leave" onClick={() => void handleLeave()} />
    </div>
  );
}
