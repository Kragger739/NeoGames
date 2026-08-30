import { useEffect, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { Check, Copy, LogOut, Rocket } from "lucide-react";

import { RoomSettingsForm } from "../components/RoomSettingsForm";
import { api } from "../lib/api";
import { DIFFICULTY_TIERS } from "../lib/difficultyTiers";
import { firstValidationError } from "../lib/errors";
import { GAME_MODES } from "../lib/gameModes";
import { leaveRoomOnServer } from "../lib/leaveRoom";
import { SONG_GENRES } from "../lib/songGenres";
import { getPlayerId } from "../lib/playerToken";
import { playSound } from "../lib/sounds";
import { EMPTY_AVATAR } from "../lib/avatarData";
import type { RoomState } from "../lib/roomTypes";
import { useAuthStore } from "../stores/authStore";
import { useFriendsStore } from "../stores/friendsStore";
import { useGameStore } from "../stores/gameStore";
import { Avatar } from "../components/ui/Avatar";
import { Button } from "../components/ui/Button";
import { IconButton } from "../components/ui/IconButton";

export function LobbyPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();
  const host = useAuthStore((state) => state.host);
  const fetchHost = useAuthStore((state) => state.fetchHost);
  const authStatus = useAuthStore((state) => state.status);
  const connectGame = useGameStore((state) => state.connect);
  const leaveRoom = useGameStore((state) => state.leaveRoom);
  const gamePhase = useGameStore((state) => state.phase);
  const members = useGameStore((state) => state.members);
  const channelError = useGameStore((state) => state.channelError);
  const liveSongsPerTier = useGameStore((state) => state.songsPerTier);
  const liveEnabledTiers = useGameStore((state) => state.enabledTiers);
  const liveGuessTimeoutSeconds = useGameStore((state) => state.guessTimeoutSeconds);
  const liveMode = useGameStore((state) => state.mode);
  const livePlayerMode = useGameStore((state) => state.playerMode);
  const liveGenre = useGameStore((state) => state.genre);
  const liveYearFrom = useGameStore((state) => state.yearFrom);
  const liveYearTo = useGameStore((state) => state.yearTo);
  const liveArtistName = useGameStore((state) => state.artistName);
  const liveArtistNames = useGameStore((state) => state.artistNames);
  const liveDatasetId = useGameStore((state) => state.datasetId);
  const liveDatasetName = useGameStore((state) => state.datasetName);
  const friends = useFriendsStore((state) => state.friends);
  const friendsStatus = useFriendsStore((state) => state.status);
  const fetchFriends = useFriendsStore((state) => state.fetch);
  const onlineUserIds = useFriendsStore((state) => state.onlineUserIds);
  const connectPresence = useFriendsStore((state) => state.connectPresence);

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
      livePlayerMode === null &&
      liveGenre === null
    ) {
      return;
    }
    setRoom((prev) =>
      prev
        ? {
            ...prev,
            songs_per_tier: liveSongsPerTier ?? prev.songs_per_tier,
            enabled_tiers: liveEnabledTiers ?? prev.enabled_tiers,
            guess_timeout_seconds: liveGuessTimeoutSeconds ?? prev.guess_timeout_seconds,
            mode: liveMode ?? prev.mode,
            player_mode: livePlayerMode ?? prev.player_mode,
            genre: liveGenre ?? prev.genre,
            // Unlike the fields above, null is a real, meaningful value
            // here (genre isn't "year") once the store has loaded at all -
            // falling back to `prev` would keep a stale range forever
            // after switching away from Year mode.
            year_from: liveYearFrom,
            year_to: liveYearTo,
            artist_name: liveArtistName,
            artist_names: liveArtistNames,
            dataset_id: liveDatasetId,
            dataset_name: liveDatasetName,
          }
        : prev,
    );
  }, [
    liveSongsPerTier,
    liveEnabledTiers,
    liveGuessTimeoutSeconds,
    liveMode,
    livePlayerMode,
    liveGenre,
    liveYearFrom,
    liveYearTo,
    liveArtistName,
    liveArtistNames,
    liveDatasetId,
    liveDatasetName,
  ]);

  useEffect(() => {
    if (!code) return;
    connectGame(code);
  }, [code, connectGame]);

  // A little celebratory blip whenever the lobby roster grows - skips the
  // very first population of the list (joining an already-full lobby
  // shouldn't fire a sound per existing member).
  const previousMemberCount = useRef<number | null>(null);
  useEffect(() => {
    if (previousMemberCount.current !== null && members.length > previousMemberCount.current) {
      playSound("join");
    }
    previousMemberCount.current = members.length;
  }, [members.length]);

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
    // Presence is needed to filter the invite list down to online friends
    // only - connectPresence() is idempotent, safe to call on every re-run.
    if (host) {
      connectPresence();
    }
  }, [host, friendsStatus, fetchFriends, connectPresence]);

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

  // Being logged in as *a* host isn't enough - has to be the host who
  // actually owns *this* room, or any other logged-in host visiting a
  // friend's lobby would see the settings form/Start button too (the
  // backend already rejects their PATCH/start attempts, but the UI showing
  // them at all is its own bug - confirmed live).
  const isHost = host !== null && room !== null && room.host_id === host.id;

  // isHost alone would hide the invite panel from a friend who joined
  // someone else's room - the backend only requires being seated
  // (FriendService::inviteToRoom), not owning the room. getPlayerId()
  // (set at join/create time) cross-referenced against the live presence
  // roster is the only client-side signal for "am I seated here."
  const playerId = getPlayerId();
  const isSeated = isHost || (playerId !== null && members.some((m) => m.id === playerId));

  // Only friends currently online (per the shared presence channel) are
  // worth showing here - inviting someone who isn't around to see it isn't
  // useful, and clutters the list.
  const onlineFriends = friends.filter((friend) => onlineUserIds.has(friend.id));

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

  async function handleLeave() {
    if (code) await leaveRoomOnServer(code);
    leaveRoom();
    navigate("/");
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
      <div className="lobby-header">
        <h1 className="room-ticket">{code?.toUpperCase()}</h1>
        <div className="lobby-header-actions">
          <Button variant="ghost" onClick={handleCopyInviteLink}>
            <Copy size={16} strokeWidth={2.5} />
            {linkCopied ? "Copied!" : "Copy invite link"}
          </Button>
          <IconButton icon={LogOut} label="Leave room" onClick={() => void handleLeave()} />
        </div>
      </div>
      {channelError && <p className="form-error">{channelError}</p>}
      {room && code && isHost && room.status === "lobby" ? (
        <RoomSettingsForm
          code={code}
          songsPerTier={room.songs_per_tier}
          enabledTiers={room.enabled_tiers}
          guessTimeoutSeconds={room.guess_timeout_seconds}
          mode={room.mode}
          playerMode={room.player_mode}
          genre={room.genre}
          yearFrom={room.year_from}
          yearTo={room.year_to}
          artistName={room.artist_name}
          artistNames={room.artist_names}
          datasetId={room.dataset_id}
          datasetName={room.dataset_name}
          hostLevel={host?.level ?? null}
        />
      ) : (
        room &&
        (room.mode === "classic" ? (
          // Classic has no configurable settings to summarize - see
          // RoomSettingsForm.tsx.
          <p className="hint">Classic mode — songs from our all-time hits playlist.</p>
        ) : (
          <p className="hint">
            {room.songs_per_tier} songs per tier ({room.enabled_tiers
              .map((t) => DIFFICULTY_TIERS.find((d) => d.value === t)?.label ?? t)
              .join(", ")}) ·{" "}
            {room.guess_timeout_seconds}s per clip stage ·{" "}
            {GAME_MODES.find((m) => m.value === room.mode)?.label ?? room.mode} ·{" "}
            {room.genre === "year" && room.year_from && room.year_to
              ? `${room.year_from}–${room.year_to}`
              : room.genre === "artist" && room.artist_name
                ? room.artist_name
                : room.genre === "multi_artist" && room.artist_names && room.artist_names.length > 0
                  ? room.artist_names.join(", ")
                  : (SONG_GENRES.find((g) => g.value === room.genre)?.label ?? room.genre)}
          </p>
        ))
      )}
      {room?.player_mode !== "solo" && (
        <>
          <h2>Players ({members.length})</h2>
          <ul className="player-list">
            {members.map((member) => (
              <li key={member.id}>
                <span className="friend-name">
                  <Avatar data={member.avatar ?? EMPTY_AVATAR} size="xs" animated={false} />
                  {member.name}
                </span>
                {member.level !== null && <span className="player-level">Lvl {member.level}</span>}
              </li>
            ))}
          </ul>
        </>
      )}

      {isSeated && host && (
        <>
          <h2>Invite a friend</h2>
          {friendsStatus !== "ready" ? (
            <p className="hint">Loading your friends…</p>
          ) : friends.length === 0 ? (
            <p className="hint">
              Add friends on the <Link to="/friends">Friends</Link> page to invite
              them into your room.
            </p>
          ) : onlineFriends.length === 0 ? (
            <p className="hint">None of your friends are online right now.</p>
          ) : (
            <ul className="player-list">
              {onlineFriends.map((friend) => (
                <li key={friend.id}>
                  <span>{friend.username}</span>
                  <Button
                    variant="turquoise"
                    disabled={invitedIds.includes(friend.id)}
                    onClick={() => void handleInvite(friend.id)}
                  >
                    {invitedIds.includes(friend.id) ? (
                      <>
                        <Check size={16} strokeWidth={2.5} />
                        Invited
                      </>
                    ) : (
                      "Invite"
                    )}
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </>
      )}

      {isHost ? (
        <>
          {startError && <p className="form-error">{startError}</p>}
          <Button size="lg" onClick={handleStart} disabled={starting}>
            {starting ? (
              "Starting…"
            ) : (
              <>
                <Rocket size={20} strokeWidth={2.5} />
                Start game
              </>
            )}
          </Button>
        </>
      ) : (
        <p className="hint">Waiting for the host to start the game…</p>
      )}
    </div>
  );
}
