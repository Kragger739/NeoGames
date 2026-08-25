import { useEffect, useRef } from "react";
import confetti from "canvas-confetti";

import { MAX_SNIPPET_SECONDS } from "../lib/snippetStages";
import type { Outcome } from "../stores/gameStore";

interface RoundRevealProps {
  outcome: Outcome;
  audioUrl: string | null;
  roundId: number | null;
  volume: number;
}

const CONFETTI_COLORS = ["#22c55e", "#4ade80", "#aa3bff", "#c084fc"];

// Deezer has no per-song play/fan count - this is the artist's overall fan
// count instead (the closest real, non-fabricated "how popular is this"
// number available), so it's framed as belonging to the artist, not the
// song specifically.
function formatFanCount(count: number): string {
  if (count >= 1_000_000) return `${(count / 1_000_000).toFixed(1).replace(/\.0$/, "")}M`;
  if (count >= 1_000) return `${(count / 1_000).toFixed(1).replace(/\.0$/, "")}K`;
  return count.toLocaleString();
}

/**
 * Big centered reveal shown after a round resolves: replays the full
 * (max-length) snippet regardless of which stage it was won/lost at, so
 * everyone hears the whole clip once, then the backend's REVEAL_DELAY
 * advances to the next round. Battle Royale reuses the exact same
 * overlay/card/confetti machinery as Classic/Solo's won/failed outcomes -
 * only the outcome line differs (survivors/eliminated lists instead of a
 * single winner).
 */
export function RoundReveal({ outcome, audioUrl, roundId, volume }: RoundRevealProps) {
  const audioRef = useRef<HTMLAudioElement | null>(null);

  // "Positive" drives the green-glow/confetti vs. red-flash/shake
  // treatment: a Classic/Solo win, or a Battle Royale round where at
  // least one active player survived.
  const isPositive =
    outcome.type === "won" ||
    (outcome.type === "battle_royale" && (outcome.survivors?.length ?? 0) > 0);

  useEffect(() => {
    if (audioRef.current) {
      audioRef.current.volume = volume;
    }
  }, [volume]);

  useEffect(() => {
    const audio = audioRef.current;
    if (!audio || !audioUrl) return;

    audio.src = audioUrl;
    audio.currentTime = 0;
    audio.volume = volume;
    void audio.play().catch(() => {});

    const stopTimer = setTimeout(() => {
      audio.pause();
    }, MAX_SNIPPET_SECONDS * 1000);

    if (isPositive) {
      confetti({ particleCount: 90, spread: 70, origin: { x: 0.2, y: 0.7 }, colors: CONFETTI_COLORS });
      confetti({ particleCount: 90, spread: 70, origin: { x: 0.8, y: 0.7 }, colors: CONFETTI_COLORS });
    }

    return () => {
      clearTimeout(stopTimer);
      audio.pause();
    };
    // Re-runs only when a new round's reveal starts, not on every prop
    // identity change (outcome/volume update independently).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roundId]);

  const { answer } = outcome;

  return (
    <div className={isPositive ? "round-reveal-overlay" : "round-reveal-overlay is-failed"}>
      <div className={isPositive ? "round-reveal-card is-won" : "round-reveal-card is-failed"}>
        {answer.album_art_url ? (
          <img
            className="round-reveal-art-large"
            src={answer.album_art_url}
            alt=""
            width={220}
            height={220}
          />
        ) : (
          <span className="round-reveal-art-large art-placeholder" aria-hidden="true" />
        )}
        <h2 className="round-reveal-title">{answer.title}</h2>
        <p className="round-reveal-artist">{answer.artist}</p>
        {answer.artist_fan_count !== null && (
          <p className="round-reveal-stats">
            👤 {formatFanCount(answer.artist_fan_count)} fans
          </p>
        )}
        {outcome.type === "won" && (
          <p className="round-reveal-outcome round-reveal-outcome-won">
            🎉 {outcome.winnerNickname} got it! (+{outcome.points} pts)
          </p>
        )}
        {outcome.type === "failed" && (
          <p className="round-reveal-outcome round-reveal-outcome-failed">
            Nobody guessed it
          </p>
        )}
        {outcome.type === "battle_royale" && (
          <>
            {(outcome.survivors?.length ?? 0) > 0 && (
              <p className="round-reveal-outcome round-reveal-outcome-won">
                Survived: {outcome.survivors!.map((p) => p.nickname).join(", ")}
              </p>
            )}
            {(outcome.eliminated?.length ?? 0) > 0 && (
              <p className="round-reveal-outcome round-reveal-outcome-failed">
                Eliminated: {outcome.eliminated!.map((p) => p.nickname).join(", ")}
              </p>
            )}
          </>
        )}
      </div>
      {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
      <audio ref={audioRef} onContextMenu={(e) => e.preventDefault()} />
    </div>
  );
}
