import { useEffect, useRef } from "react";
import confetti from "canvas-confetti";
import { Users } from "lucide-react";

import { MAX_SNIPPET_SECONDS } from "../lib/snippetStages";
import { playSound } from "../lib/sounds";
import type { Outcome } from "../stores/gameStore";
import { ConfettiBurst, WhiffedIt } from "./illustrations/OutcomeBadge";

interface RoundRevealProps {
  outcome: Outcome;
  audioUrl: string | null;
  roundId: number | null;
  volume: number;
}

const CONFETTI_COLORS = ["#FF5C7A", "#17C3B2", "#FFC93C", "#8B5CF6", "#FF6FB5"];

// Deezer has no per-song play/fan count - this is the artist's overall fan
// count instead (the closest real, non-fabricated "how popular is this"
// number available), so it's framed as belonging to the artist, not the
// song specifically.
function formatFanCount(count: number): string {
  if (count >= 1_000_000) return `${(count / 1_000_000).toFixed(1).replace(/\.0$/, "")}M`;
  if (count >= 1_000) return `${(count / 1_000).toFixed(1).replace(/\.0$/, "")}K`;
  return count.toLocaleString();
}

// null for an anonymous (nickname-only) player, who has no account/level.
function formatPlayerLabel(nickname: string, level: number | null | undefined): string {
  return level != null ? `${nickname} (Lvl ${level})` : nickname;
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

    let confettiTimer: ReturnType<typeof setInterval> | undefined;

    if (isPositive) {
      const burst = () => {
        confetti({ particleCount: 90, spread: 70, origin: { x: 0.2, y: 0.7 }, colors: CONFETTI_COLORS });
        confetti({ particleCount: 90, spread: 70, origin: { x: 0.8, y: 0.7 }, colors: CONFETTI_COLORS });
      };
      burst();
      // Keeps bursting for as long as this reveal card is on screen (the
      // backend's REVEAL_DELAY_SECONDS window, ~18s) instead of a single
      // burst that fizzles out long before the next round starts.
      confettiTimer = setInterval(burst, 2500);
      playSound("win");
    } else {
      playSound("fail");
    }

    return () => {
      clearTimeout(stopTimer);
      clearInterval(confettiTimer);
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
        {isPositive ? <ConfettiBurst className="round-reveal-badge" /> : <WhiffedIt className="round-reveal-badge" />}
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
            <Users size={15} strokeWidth={2.5} />
            {formatFanCount(answer.artist_fan_count)} fans
          </p>
        )}
        <p className="round-reveal-links">
          <a
            href={`https://www.deezer.com/track/${answer.deezer_track_id}`}
            target="_blank"
            rel="noopener noreferrer"
          >
            Deezer
          </a>
          <a
            href={`https://www.youtube.com/results?search_query=${encodeURIComponent(`${answer.title} ${answer.artist}`)}`}
            target="_blank"
            rel="noopener noreferrer"
          >
            YouTube
          </a>
          <a
            href={`https://open.spotify.com/search/${encodeURIComponent(`${answer.title} ${answer.artist}`)}`}
            target="_blank"
            rel="noopener noreferrer"
          >
            Spotify
          </a>
        </p>
        {outcome.type === "won" && (
          <p className="round-reveal-outcome round-reveal-outcome-won">
            {formatPlayerLabel(outcome.winnerNickname!, outcome.winnerLevel)} got it! (+{outcome.points} pts)
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
                Survived: {outcome.survivors!.map((p) => formatPlayerLabel(p.nickname, p.level)).join(", ")}
              </p>
            )}
            {(outcome.eliminated?.length ?? 0) > 0 && (
              <p className="round-reveal-outcome round-reveal-outcome-failed">
                Eliminated: {outcome.eliminated!.map((p) => formatPlayerLabel(p.nickname, p.level)).join(", ")}
              </p>
            )}
          </>
        )}
        {roundId !== null && <p className="round-reveal-debug-id">Round #{roundId}</p>}
      </div>
      {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
      <audio ref={audioRef} onContextMenu={(e) => e.preventDefault()} />
    </div>
  );
}
