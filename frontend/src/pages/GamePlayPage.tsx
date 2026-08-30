import { useEffect, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { LogOut, Volume2, VolumeX } from "lucide-react";

import { GuessAutocomplete } from "../components/GuessAutocomplete";
import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { RoundReveal } from "../components/RoundReveal";
import { useCountdown } from "../hooks/useCountdown";
import { useVolume } from "../hooks/useVolume";
import { leaveRoomOnServer } from "../lib/leaveRoom";
import { getPlayerId, getPlayerToken } from "../lib/playerToken";
import { isSoundMuted, playSound, setSoundMuted } from "../lib/sounds";
import {
  MAX_SNIPPET_SECONDS,
  SNIPPET_STAGE_SEQUENCE,
  getStageSegments,
} from "../lib/snippetStages";
import { EMPTY_AVATAR } from "../lib/avatarData";
import { useGameStore } from "../stores/gameStore";
import { Avatar } from "../components/ui/Avatar";
import { IconButton } from "../components/ui/IconButton";

export function GamePlayPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();

  const connect = useGameStore((state) => state.connect);
  const leaveRoom = useGameStore((state) => state.leaveRoom);
  const phase = useGameStore((state) => state.phase);
  const round = useGameStore((state) => state.round);
  const tier = useGameStore((state) => state.tier);
  const roundNumber = useGameStore((state) => state.roundNumber);
  const totalRounds = useGameStore((state) => state.totalRounds);
  const outcome = useGameStore((state) => state.outcome);
  const missedNotices = useGameStore((state) => state.missedNotices);
  const players = useGameStore((state) => state.players);
  const guessTimeoutSeconds = useGameStore((state) => state.guessTimeoutSeconds);
  const playerMode = useGameStore((state) => state.playerMode);

  const audioRef = useRef<HTMLAudioElement | null>(null);
  const fillRef = useRef<HTMLDivElement | null>(null);
  const stopTimerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const [volume, setVolume] = useVolume();
  const [isPlaying, setIsPlaying] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [alreadyGuessedCorrectly, setAlreadyGuessedCorrectly] = useState(false);
  const [muted, setMuted] = useState(isSoundMuted);
  const isPlayer = getPlayerToken() !== null;
  const myPlayerId = getPlayerId();
  const amIEliminated = players.some((p) => p.id === myPlayerId && p.is_eliminated);

  // The backend's escalation timer now starts counting only after the
  // clip itself finishes playing (round.stage seconds), not concurrently
  // with it - this countdown has to add the same stage duration or it'll
  // show a deadline that no longer matches when escalation actually fires.
  const secondsLeft = useCountdown(
    phase === "playing" ? (round?.server_time ?? null) : null,
    round && guessTimeoutSeconds !== null ? round.stage + guessTimeoutSeconds : null,
  );

  // A single tick as the guess timer enters its final 3 seconds - a nudge,
  // not a metronome, so it fires once per threshold-crossing rather than
  // once per second.
  const lastTickedRound = useRef<number | null>(null);
  useEffect(() => {
    if (
      secondsLeft !== null &&
      secondsLeft <= 3 &&
      secondsLeft > 0 &&
      lastTickedRound.current !== round?.round_id
    ) {
      lastTickedRound.current = round?.round_id ?? null;
      playSound("tick");
    }
  }, [secondsLeft, round?.round_id]);

  useEffect(() => {
    if (!code) return;
    connect(code);
  }, [code, connect]);

  useEffect(() => {
    if (phase === "finished" && code) {
      navigate(`/rooms/${code}/results`);
    }
  }, [phase, code, navigate]);

  useEffect(() => {
    setError(null);
    setAlreadyGuessedCorrectly(false);
  }, [round?.round_id]);

  useEffect(() => {
    if (audioRef.current) {
      audioRef.current.volume = volume;
    }
  }, [volume]);

  /**
   * The only way audio ever plays: always resets to 0:00 and hard-stops
   * after exactly `round.stage` seconds, regardless of how many times it's
   * triggered. There's no native <audio controls> (seek bar, "Save Audio
   * As…") - this is the sole playback surface.
   */
  function playClip() {
    const audio = audioRef.current;
    if (!audio || !round?.audio_url) return;

    clearTimeout(stopTimerRef.current);
    audio.pause();
    audio.currentTime = 0;
    audio.volume = volume;

    // Snap the playhead fill back to empty (no transition) before arming
    // the real transition, so replaying the same stage re-animates from
    // 0 instead of jumping - the reflow forces the browser to apply the
    // scaleX(0) before the transition property change takes effect.
    // transform (not width) so the browser can animate it on the
    // compositor instead of triggering layout on every frame.
    const fill = fillRef.current;
    if (fill) {
      fill.style.transition = "none";
      fill.style.transform = "scaleX(0)";
      void fill.offsetWidth;
      fill.style.transition = `transform ${round.stage}s linear`;
    }

    void audio
      .play()
      .then(() => {
        setIsPlaying(true);
        if (fill) {
          fill.style.transform = `scaleX(${round.stage / MAX_SNIPPET_SECONDS})`;
        }
      })
      .catch(() => setIsPlaying(false));

    stopTimerRef.current = setTimeout(() => {
      audio.pause();
      setIsPlaying(false);
    }, round.stage * 1000);
  }

  useEffect(() => {
    const audio = audioRef.current;
    if (!round?.audio_url || !audio) return;

    audio.src = round.audio_url;
    // Best-effort autoplay; browsers may block it without a user gesture,
    // in which case the Play button is the fallback.
    playClip();

    return () => {
      clearTimeout(stopTimerRef.current);
      audio.pause();
      setIsPlaying(false);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [round]);

  async function handleLeave() {
    if (code) await leaveRoomOnServer(code);
    leaveRoom();
    navigate("/");
  }

  function toggleMuted() {
    const next = !muted;
    setSoundMuted(next);
    setMuted(next);
  }

  async function submitGuess(guess: string) {
    if (!round || !guess.trim()) return;
    setError(null);
    setSubmitting(true);
    try {
      const response = await api.post<{ correct: boolean; won: boolean }>(
        `/api/rounds/${round.round_id}/guess`,
        { guess },
      );
      if (response.data.correct) {
        setAlreadyGuessedCorrectly(true);
        playSound("correct");
      }
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="game-play-page">
      <div className="game-play-layout">
        <div className="game-play-main">
          <div className="game-play-header">
            <h1>Room {code?.toUpperCase()}</h1>
            <IconButton icon={LogOut} label="Leave room" onClick={() => void handleLeave()} />
          </div>
          {tier && (
            <p className="hint">
              Tier: {tier}
              {roundNumber !== null && totalRounds !== null && (
                <span> · Round {roundNumber} of {totalRounds}</span>
              )}
            </p>
          )}

          <div className="volume-control">
            <IconButton
              icon={muted ? VolumeX : Volume2}
              label={muted ? "Unmute sound effects" : "Mute sound effects"}
              onClick={toggleMuted}
            />
            <input
              id="volume"
              type="range"
              min={0}
              max={1}
              step={0.01}
              value={volume}
              onChange={(e) => setVolume(Number(e.target.value))}
              style={{
                background: `linear-gradient(to right, var(--coral) ${volume * 100}%, var(--line) ${volume * 100}%)`,
              }}
            />
          </div>

          {phase === "playing" && round && (
            <>
              <p className="stage-info">
                Snippet length: {round.stage}s
                {playerMode !== "solo" && secondsLeft !== null && (
                  <span className="countdown"> · extends in {secondsLeft}s</span>
                )}
              </p>
              <div className="stage-progress">
                {getStageSegments().map((seg, i) => (
                  <div
                    key={seg.stage}
                    className={
                      i <= SNIPPET_STAGE_SEQUENCE.indexOf(round.stage)
                        ? "stage-progress-segment stage-progress-segment-filled"
                        : "stage-progress-segment"
                    }
                    style={{ left: `${seg.startPct}%`, width: `${seg.widthPct}%` }}
                  />
                ))}
                <div
                  ref={fillRef}
                  className="stage-progress-fill"
                  style={{ transform: "scaleX(0)" }}
                />
              </div>
              {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
              <audio ref={audioRef} onContextMenu={(e) => e.preventDefault()} />
              <button
                type="button"
                className="play-clip-button"
                onClick={playClip}
                disabled={isPlaying}
              >
                {isPlaying ? "Playing…" : "▶ Play clip"}
              </button>
              {!isPlayer ? (
                <p className="hint">
                  You're hosting — your friends are guessing on their own
                  screens.
                </p>
              ) : amIEliminated ? (
                <p className="hint">You've been eliminated — spectating the rest of the game.</p>
              ) : alreadyGuessedCorrectly ? (
                <p>You guessed it! Waiting for the round to close…</p>
              ) : (
                <>
                  <GuessAutocomplete
                    disabled={submitting}
                    submitting={submitting}
                    isSolo={playerMode === "solo"}
                    onSubmit={submitGuess}
                  />
                  {error && <p className="form-error">{error}</p>}
                </>
              )}
            </>
          )}

          {phase === "revealed" && outcome && (
            <RoundReveal
              outcome={outcome}
              audioUrl={round?.audio_url ?? null}
              roundId={round?.round_id ?? null}
              volume={volume}
            />
          )}

          {missedNotices.length > 0 && phase === "playing" && (
            <ul className="miss-feed">
              {missedNotices.map((nickname, i) => (
                <li key={i}>{nickname} guessed wrong</li>
              ))}
            </ul>
          )}
        </div>

        <aside className="scoreboard-panel">
          <h2>Scores</h2>
          <ol className="scoreboard">
            {[...players]
              .sort((a, b) => b.score - a.score)
              .map((player) => (
                <li key={player.id}>
                  <span className="friend-name">
                    <Avatar data={player.avatar ?? EMPTY_AVATAR} size="xs" animated={false} />
                    {player.nickname}
                    {player.is_eliminated && " (out)"}
                  </span>
                  <span>{player.score}</span>
                </li>
              ))}
          </ol>
        </aside>
      </div>
    </div>
  );
}
