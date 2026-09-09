import { useEffect } from "react";

import { leaveRoomBeacon } from "../lib/leaveRoom";

/**
 * Drops the caller's seat the moment the tab/browser actually closes -
 * `pagehide` (not `beforeunload`, which mobile browsers can skip entirely)
 * fires reliably on tab close, browser quit, and mobile swipe-away, but
 * never on in-app client-side navigation (React Router doesn't unload the
 * page), so this can't double-fire alongside a normal "Leave room" click.
 * Without this, a player who just closes the site stays seated forever -
 * ghosting the scoreboard/lobby roster until someone else leaves and the
 * room happens to empty out.
 */
export function useLeaveRoomOnClose(code: string | undefined): void {
  useEffect(() => {
    if (!code) return;

    const handleClose = () => leaveRoomBeacon(code);
    window.addEventListener("pagehide", handleClose);
    return () => window.removeEventListener("pagehide", handleClose);
  }, [code]);
}
