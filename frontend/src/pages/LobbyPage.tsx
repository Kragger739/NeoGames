import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";

import { RoomSettingsForm } from "../components/RoomSettingsForm";
import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { GAME_MODES } from "../lib/gameModes";
import { SONG_GENRES } from "../lib/songGenres";
import type { RoomState } from "../lib/roomTypes";
import { useAuthStore } from "../stores/authStore";
import { useFriendsStore } from "../stores/friendsStore";
import { useGameStore } from "../stores/gameStore";

export function LobbyPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();
  const host = useAuthStore((state) => state.host);
  const fetchHost = useAuthStore((state) => state.fetchHost);
  const authStatus = useAuthStore((state) => state.status);
  const connectGame = useGameStore((state) => state.connect);
  const gamePhase = useGameStore((state) => state.phase);
  const members = useGameStore((state) => state.members);
  const channelError = useGameStore((state) => state.channelError);
  const liveSongsPerTier = useGameStore((state) => state.songsPerTier);
  const liveGuessTimeoutSeconds = useGameStore((state) => state.guessTimeoutSeconds);
  const liveMode = useGameStore((state) => state.mode);
  const liveGenre = useGameStore((state) => state.genre);
  const liveYearFrom = useGameStore((state) => state.yearFrom);
  const liveYearTo = useGameStore((state) => state.yearTo);
  const liveArtistName = useGameStore((state) => state.artistName);
  const friends = useFriendsStore((state) => state.friends);
  const friendsStatus = useFriendsStore((state) => state.status);
  const fetchFriends = useFriendsStore((state) => state.fetch);

  const [room, setRoom] = useState<RoomState | null>(null);
  const [startError, setStartError] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);
  const [linkCopied, setLinkCopied] = useState(false);
  const [invitedIds, setInvitedIds] = useState<number[]>([]);

  useEffect(() => {
    // Always attempted (not just when there's no player token): the host
    // now also carries a player token for their own room, so the two are
    // no longer mutually exclusive. A non-host browser just gets a quiet
    // 401 here and `host` stays null.
    if (authStatus === "idle") {
      void fetchHost();
    }
  }, [authStatus, fetchHost]);

  useEffect(() => {
    if (!code) return;
    void api.get<RoomState>(`/api/rooms/${code}`).then((response) => {
      setRoom(response.data);
    });
  }, [code]);

  // gameStore owns the room's live channel subscription and already
  // listens for room.settings_updated - mirror its values into the local
  // `room` snapshot whenever they change (initial catch-up GET included),
  // so this page's settings display/form stays live without its own
  // second subscription.
  useEffect(() => {
    if (
      liveSongsPerTier === null &&
      liveGuessTimeoutSeconds === null &&
      liveMode === null &&
      liveGenre === null
    ) {
      return;
    }
    setRoom((prev) =>
      prev
        ? {
            ...prev,
            songs_per_tier: liveSongsPerTier ?? prev.songs_per_tier,
            guess_timeout_seconds: liveGuessTimeoutSeconds ?? prev.guess_timeout_seconds,
            mode: liveMode ?? prev.mode,
            genre: liveGenre ?? prev.genre,
            // Unlike the fields above, null is a real, meaningful value
            // here (genre isn't "year") once the store has loaded at all -
            // falling back to `prev` would keep a stale range forever
            // after switching away from Year mode.
            year_from: liveYearFrom,
            year_to: liveYearTo,
            artist_name: liveArtistName,
          }
        : prev,
    );
  }, [
    liveSongsPerTier,
    liveGuessTimeoutSeconds,
    liveMode,
    liveGenre,
    liveYearFrom,
    liveYearTo,
    liveArtistName,
  ]);

  useEffect(() => {
    if (!code) return;
    connectGame(code);
  }, [code, connectGame]);

  useEffect(() => {
    if (gamePhase === "playing" && code) {
      navigate(`/rooms/${code}/play`);
    }
  }, [gamePhase, code, navigate]);

  useEffect(() => {
    // Any logged-in, seated player (not just the room's host) can invite
    // friends into a room they're in - matches the backend's own check.
    if (host && friendsStatus === "idle") {
      void fetchFriends();
    }
  }, [host, friendsStatus, fetchFriends]);

  async function handleStart() {
    if (!code) return;
    setStartError(null);
    setStarting(true);
    try {
      await api.post(`/api/rooms/${code}/start`);
      // Screen transition happens via the round.started broadcast, not here.
    } catch (err) {
      setStartError(firstValidationError(err));
      setStarting(false);
    }
  }

  const isHost = host !== null;

  async function handleCopyInviteLink() {
    if (!code) return;
    const link = `${window.location.origin}/play/${code}`;
    try {
      await navigator.clipboard.writeText(link);
      setLinkCopied(true);
      setTimeout(() => setLinkCopied(false), 2000);
    } catch {
      // Clipboard access can be blocked (permissions, insecure context);
      // there's no good fallback UI here, so just leave the button as-is.
    }
  }

  async function handleInvite(friendId: number) {
    if (!code) return;
    try {
      await api.post(`/api/rooms/${code}/invite`, { friend_user_id: friendId });
      setInvitedIds((ids) => [...ids, friendId]);
    } catch {
      // Best-effort - the friend list stays interactive either way.
    }
  }

  return (
    <div className="lobby-page">
      <h1>Room {code?.toUpperCase()}</h1>
      <button type="button" className="copy-invite-button" onClick={handleCopyInviteLink}>
        {linkCopied ? "Copied!" : "Copy invite link"}
      </button>
      {channelError && <p className="form-error">{channelError}</p>}
      {room && code && isHost && room.status === "lobby" ? (
        <RoomSettingsForm
          code={code}
          songsPerTier={room.songs_per_tier}
          guessTimeoutSeconds={room.guess_timeout_seconds}
          mode={room.mode}
          genre={room.genre}
          yearFrom={room.year_from}
          yearTo={room.year_to}
          artistName={room.artist_name}
        />
      ) : (
        room && (
          <p className="hint">
            {room.songs_per_tier} songs per tier · {room.guess_timeout_seconds}s
            per clip stage ·{" "}
            {GAME_MODES.find((m) => m.value === room.mode)?.label ?? room.mode} ·{" "}
            {room.genre === "year" && room.year_from && room.year_to
              ? `${room.year_from}–${room.year_to}`
              : room.genre === "artist" && room.artist_name
                ? room.artist_name
                : (SONG_GENRES.find((g) => g.value === room.genre)?.label ?? room.genre)}
          </p>
        )
      )}
      <h2>Players ({members.length})</h2>
      <ul className="player-list">
        {members.map((member) => (
          <li key={member.id}>{member.name}</li>
        ))}
      </ul>

      {isHost && friends.length > 0 && (
        <>
          <h2>Invite a friend</h2>
          <ul className="player-list">
            {friends.map((friend) => (
              <li key={friend.id}>
                <span>{friend.username}</span>
                <button
                  type="button"
                  className="button-secondary"
                  disabled={invitedIds.includes(friend.id)}
                  onClick={() => void handleInvite(friend.id)}
                >
                  {invitedIds.includes(friend.id) ? "Invited" : "Invite"}
                </button>
              </li>
            ))}
          </ul>
        </>
      )}

      {isHost ? (
        <>
          {startError && <p className="form-error">{startError}</p>}
          <button onClick={handleStart} disabled={starting}>
            {starting ? "Starting…" : "Start game"}
          </button>
        </>
      ) : (
        <p>Waiting for the host to start the game…</p>
      )}
    </div>
  );
}
