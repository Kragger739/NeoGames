import { api } from "./api";
import { clearPlayerToken, getPlayerToken } from "./playerToken";

const apiUrl = import.meta.env.VITE_API_URL as string;

/**
 * Tells the backend to drop the caller's own seat (and delete the room
 * entirely once nobody's left in it - see RoomPlayerController::destroy()),
 * then clears the now-invalid player token regardless of whether the
 * request actually succeeded - a player backing out of a dead connection
 * shouldn't get stuck on the leave button.
 */
export async function leaveRoomOnServer(code: string): Promise<void> {
  try {
    await api.delete(`/api/rooms/${code}/leave`);
  } catch {
    // Best-effort - local cleanup/navigation proceeds either way.
  }
  clearPlayerToken();
}

/** Laravel's non-HttpOnly XSRF-TOKEN cookie, url-decoded - what axios's
 *  withXSRFToken reads automatically, replicated here since fetch has no
 *  built-in equivalent. */
function readXsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
}

/**
 * Same seat-drop as leaveRoomOnServer(), but for when the tab is actually
 * closing (see useLeaveRoomOnClose) rather than an in-app "Leave" click.
 * A page that's unloading can't be trusted to let a normal axios call
 * finish, so this uses `fetch` with `keepalive` instead - the browser
 * queues the request and lets it complete after the page is gone.
 * sendBeacon isn't an option here: it's POST-only with no custom headers,
 * and this endpoint needs X-Player-Token/X-XSRF-TOKEN to authenticate.
 * Fire-and-forget by design - there's no page left to await a response on.
 */
export function leaveRoomBeacon(code: string): void {
  const playerToken = getPlayerToken();
  const xsrfToken = readXsrfToken();

  void fetch(`${apiUrl}/api/rooms/${code}/leave`, {
    method: "DELETE",
    keepalive: true,
    credentials: "include",
    headers: {
      Accept: "application/json",
      ...(playerToken ? { "X-Player-Token": playerToken } : {}),
      ...(xsrfToken ? { "X-XSRF-TOKEN": xsrfToken } : {}),
    },
  }).catch(() => {});
}
