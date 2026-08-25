const STORAGE_KEY = "neogames_player_token";
const ID_STORAGE_KEY = "neogames_player_id";

/**
 * Player identity is deliberately session-only (sessionStorage, not
 * localStorage): closing the tab means the player can't rejoin as
 * themself, which is an intentional trade-off, not an oversight.
 */
export function getPlayerToken(): string | null {
  return sessionStorage.getItem(STORAGE_KEY);
}

export function setPlayerToken(token: string): void {
  sessionStorage.setItem(STORAGE_KEY, token);
}

export function clearPlayerToken(): void {
  sessionStorage.removeItem(STORAGE_KEY);
}

/**
 * This browser's own room_players.id - lets the UI tell whether *this*
 * player is one of the ones a Battle Royale round just eliminated.
 */
export function getPlayerId(): number | null {
  const raw = sessionStorage.getItem(ID_STORAGE_KEY);
  return raw === null ? null : Number(raw);
}

export function setPlayerId(id: number): void {
  sessionStorage.setItem(ID_STORAGE_KEY, String(id));
}
