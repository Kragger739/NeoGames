import { useEffect, useState } from "react";

/**
 * Seconds remaining until serverTime + totalSeconds, ticking once a second.
 * Resyncs whenever serverTime or totalSeconds changes (a new stage/round).
 */
export function useCountdown(
  serverTime: string | null,
  totalSeconds: number | null,
): number | null {
  const [remaining, setRemaining] = useState<number | null>(null);

  useEffect(() => {
    if (!serverTime || totalSeconds === null) {
      setRemaining(null);
      return;
    }

    const deadline = new Date(serverTime).getTime() + totalSeconds * 1000;

    function tick() {
      setRemaining(Math.max(0, Math.ceil((deadline - Date.now()) / 1000)));
    }

    tick();
    const interval = setInterval(tick, 250);

    return () => clearInterval(interval);
  }, [serverTime, totalSeconds]);

  return remaining;
}
