import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { dubPathForState } from "../lib/dubNav";
import { getPlayerId } from "../lib/playerToken";
import { useDubStore } from "../stores/dubStore";
import { Button } from "../components/ui/Button";
import { DubClipPicker } from "../components/dub/DubClipPicker";

export function DubWatchPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();

  const connect = useDubStore((s) => s.connect);
  const resync = useDubStore((s) => s.resync);
  const state = useDubStore((s) => s.state);
  const playerMode = useDubStore((s) => s.playerMode);
  const packId = useDubStore((s) => s.packId);
  const assembledVideoUrl = useDubStore((s) => s.assembledVideoUrl);
  const players = useDubStore((s) => s.players);
  const ratingCount = useDubStore((s) => s.ratingCount);
  const ratingTotal = useDubStore((s) => s.ratingTotal);
  const myRating = useDubStore((s) => s.myRating);
  const roundNumber = useDubStore((s) => s.roundNumber);
  const lastRoundScore = useDubStore((s) => s.lastRoundScore);
  const totalScore = useDubStore((s) => s.totalScore);
  const scoreBreakdown = useDubStore((s) => s.scoreBreakdown);
  const caughtUp = useDubStore((s) => s.caughtUp);

  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const myPlayerId = getPlayerId();
  const iAmHostSeat = players.find((p) => p.room_player_id === myPlayerId)?.is_host ?? false;
  const solo = playerMode === "solo";
  const isPack = packId != null;

  useEffect(() => {
    if (code) connect(code);
  }, [code, connect]);

  useEffect(() => {
    if (
      caughtUp &&
      code &&
      state !== "watch" &&
      state !== "rating" &&
      state !== "round_complete"
    ) {
      navigate(dubPathForState(code, state));
    }
  }, [caughtUp, state, code, navigate]);

  async function act(path: string, body?: unknown) {
    if (!code) return;
    setError(null);
    setBusy(true);
    try {
      await api.post(`/api/dub-rooms/${code}/${path}`, body);
      await resync(code);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setBusy(false);
    }
  }

  async function rate(score: number) {
    if (!code) return;
    setError(null);
    try {
      await api.post(`/api/dub-rooms/${code}/ratings`, { score });
      await resync(code);
    } catch (err) {
      setError(firstValidationError(err));
    }
  }

  return (
    <div className="dub-watch-page">
      <h1>Round {roundNumber} — your dub</h1>

      {assembledVideoUrl ? (
        <video className="dub-watch-video" src={assembledVideoUrl} controls playsInline />
      ) : (
        <p className="hint">Waiting for the dub…</p>
      )}

      {assembledVideoUrl && (
        <a className="btn btn-ghost" href={assembledVideoUrl} download={`dub-round-${roundNumber}.mp4`}>
          Download
        </a>
      )}

      {error && <p className="form-error">{error}</p>}

      {state === "watch" && (
        <Button variant="grape" size="lg" disabled={busy} onClick={() => void act("watch-done")}>
          {solo ? "Continue" : "Continue to ratings"}
        </Button>
      )}

      {state === "rating" && (
        <section className="dub-rating">
          <h2>Rate the dub</h2>
          <div className="dub-rating-stars">
            {[1, 2, 3, 4, 5].map((n) => (
              <button
                key={n}
                type="button"
                className={myRating != null && n <= myRating ? "is-on" : ""}
                disabled={myRating != null}
                onClick={() => void rate(n)}
                aria-label={`${n} star${n > 1 ? "s" : ""}`}
              >
                ★
              </button>
            ))}
          </div>
          <p className="hint">
            {myRating != null ? "Rating locked in." : "Pick 1–5."} {ratingCount}/{ratingTotal} rated
          </p>
          {iAmHostSeat && (
            <Button variant="ghost" disabled={busy} onClick={() => void act("end-rating")}>
              Skip waiting — score now
            </Button>
          )}
        </section>
      )}

      {state === "round_complete" && (
        <section className="dub-score-callout">
          {solo ? (
            <h2>Nice one!</h2>
          ) : (
            <>
              <h2>Round score: {lastRoundScore?.toFixed(2) ?? "—"}</h2>
              <p className="hint">Running total: {totalScore.toFixed(2)}</p>
              <ul className="dub-score-breakdown">
                {scoreBreakdown.map((b) => (
                  <li key={b.room_player_id}>
                    <span>{b.nickname}</span>
                    <span>{b.score ? `${b.score}★` : "—"}</span>
                  </li>
                ))}
              </ul>
            </>
          )}
          {iAmHostSeat ? (
            isPack ? (
              <>
                <Button variant="grape" size="lg" disabled={busy} onClick={() => void act("next-round")}>
                  Next round
                </Button>
                <Button variant="ghost" disabled={busy} onClick={() => void act("finish")}>
                  Finish now
                </Button>
                <p className="hint">"Next round" plays the pack's next clip, or ends the game when it runs out.</p>
              </>
            ) : (
              <>
                <h3>{solo ? "Another clip?" : "Play another clip"}</h3>
                <DubClipPicker
                  selectedClipId={null}
                  disabled={busy}
                  onPick={(clipId) => act("next-round", { clip_id: clipId })}
                />
                <Button variant="grape" size="lg" disabled={busy} onClick={() => void act("finish")}>
                  Finish game
                </Button>
              </>
            )
          ) : (
            <p className="hint">Waiting for the host to continue or finish…</p>
          )}
        </section>
      )}
    </div>
  );
}
