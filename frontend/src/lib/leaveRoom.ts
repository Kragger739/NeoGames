import { api } from "./api";
import { clearPlayerToken } from "./playerToken";

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
