import { useCountdown } from "../../hooks/useCountdown";

interface DdfCountdownTimerProps {
  serverTime: string | null;
  durationSeconds: number | null;
  isPaused?: boolean;
}

/**
 * Server-authoritative countdown - the number is purely a render of
 * server_time + duration, drift-adjusted client-side (useCountdown),
 * never something a client could advance on its own. Fill color escalates
 * turquoise -> sunflower -> coral as time runs low.
 */
export function DdfCountdownTimer({ serverTime, durationSeconds, isPaused = false }: DdfCountdownTimerProps) {
  const secondsLeft = useCountdown(isPaused ? null : serverTime, durationSeconds);
  const displayed = isPaused ? durationSeconds : secondsLeft;

  if (displayed === null) return null;

  const urgency = displayed <= 5 ? "danger" : displayed <= 10 ? "warning" : "normal";

  return (
    <div className={`ddf-countdown ddf-countdown-${urgency}`}>
      <span className="ddf-countdown-number">{displayed}</span>
      {isPaused && <span className="ddf-countdown-paused">PAUSED</span>}
    </div>
  );
}
