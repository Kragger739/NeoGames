import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Copy, LogOut } from "lucide-react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { leaveRoomOnServer } from "../lib/leaveRoom";
import { getPlayerId } from "../lib/playerToken";
import { useWebcamMesh } from "../lib/webrtc";
import { useAuthStore } from "../stores/authStore";
import { useDdfStore } from "../stores/ddfStore";
import { Button } from "../components/ui/Button";
import { IconButton } from "../components/ui/IconButton";
import { AvConsentGate } from "../components/ddf/AvConsentGate";
import { DdfWebcamCard } from "../components/ddf/DdfWebcamCard";
import { DdfWebcamGrid } from "../components/ddf/DdfWebcamGrid";
import { DdfGmSettingsPanel } from "../components/ddf/gm/DdfGmSettingsPanel";

export function DdfLobbyPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();
  const host = useAuthStore((state) => state.host);
  const fetchHost = useAuthStore((state) => state.fetchHost);
  const authStatus = useAuthStore((state) => state.status);

  const connect = useDdfStore((s) => s.connect);
  const resync = useDdfStore((s) => s.resync);
  const leaveRoom = useDdfStore((s) => s.leaveRoom);
  const state = useDdfStore((s) => s.state);
  const hostId = useDdfStore((s) => s.hostId);
  const hostName = useDdfStore((s) => s.hostName);
  const players = useDdfStore((s) => s.players);
  const members = useDdfStore((s) => s.members);
  const caughtUp = useDdfStore((s) => s.caughtUp);
  const roundsPerVoting = useDdfStore((s) => s.roundsPerVoting);
  const questionTimerSeconds = useDdfStore((s) => s.questionTimerSeconds);
  const votingTimerSeconds = useDdfStore((s) => s.votingTimerSeconds);
  const language = useDdfStore((s) => s.language);
  const couchMode = useDdfStore((s) => s.couchMode);
  const safeMode = useDdfStore((s) => s.safeMode);

  // Being logged in as *a* host isn't enough - has to be the host who
  // actually owns *this* room (matches LobbyPage.tsx's own isHost check).
  // A bare "no player token in this browser" check breaks the moment this
  // tab ever holds a leftover player token from some other room (e.g. the
  // host previously joined a friend's game) - confirmed live.
  const isGm = caughtUp && host !== null && hostId !== null && host.id === hostId;
  const myPlayerId = getPlayerId();
  const selfId: string | number | null = isGm ? "host" : myPlayerId;

  const { localStream, remoteStreams, mediaError } = useWebcamMesh(code ?? null, selfId, members);
  const [isReady, setIsReady] = useState(false);
  const [startError, setStartError] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);
  const [linkCopied, setLinkCopied] = useState(false);

  useEffect(() => {
    if (authStatus === "idle") void fetchHost();
  }, [authStatus, fetchHost]);

  useEffect(() => {
    if (!code) return;
    connect(code);
  }, [code, connect]);

  useEffect(() => {
    if (caughtUp && state !== "lobby") {
      navigate(isGm ? `/ddf-rooms/${code}/gm` : `/ddf-rooms/${code}/play`);
    }
  }, [caughtUp, state, isGm, code, navigate]);

  async function toggleReady() {
    if (!code) return;
    const next = !isReady;
    setIsReady(next);
    try {
      await api.patch(`/api/ddf-rooms/${code}/ready`, { is_camera_ready: next });
    } catch {
      setIsReady(!next);
    }
  }

  async function handleStart() {
    if (!code) return;
    setStartError(null);
    setStarting(true);
    try {
      await api.post(`/api/ddf-rooms/${code}/start`);
      // Navigation is driven by `state` leaving "lobby" (the effect below).
      // Normally the .ddf.game_started broadcast does that; pull the
      // authoritative state too so a dropped broadcast can't leave the GM
      // stuck on the lobby (then clicking Start again -> "already started").
      await resync(code);
    } catch (err) {
      setStartError(firstValidationError(err));
      // "This game has already started" means the previous click did work
      // but its broadcast was missed - re-sync so the effect can navigate.
      void resync(code).catch(() => {});
    } finally {
      setStarting(false);
    }
  }

  async function handleLeave() {
    if (code) await leaveRoomOnServer(code);
    leaveRoom();
    navigate("/");
  }

  const activePlayers = players.filter((p) => !p.is_eliminated);
  const allReady = activePlayers.length >= 2 && activePlayers.every((p) => p.is_camera_ready);

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

  return (
    <AvConsentGate>
    <div className="ddf-lobby-page">
      <h1>DER DÜMMSTE FLIEGT</h1>
      <div className="ddf-lobby-invite">
        <p className="room-ticket">{code?.toUpperCase()}</p>
        <Button variant="ghost" onClick={() => void handleCopyInviteLink()}>
          <Copy size={16} strokeWidth={2.5} />
          {linkCopied ? "Copied!" : "Copy invite link"}
        </Button>
        <IconButton icon={LogOut} label="Leave room" onClick={() => void handleLeave()} />
      </div>

      <div className="ddf-lobby-self-preview">
        <DdfWebcamCard name={isGm ? `👑 ${host?.name ?? "Game Master"}` : "You"} stream={localStream} muted />
        {mediaError && <p className="form-error">Camera/mic error: {mediaError}</p>}
        {!isGm && (
          <Button variant={isReady ? "turquoise" : "ghost"} onClick={() => void toggleReady()}>
            {isReady ? "Ready ✓" : "I'm ready"}
          </Button>
        )}
      </div>

      <h2>Players</h2>
      <DdfWebcamGrid>
        {!isGm && (
          <div className="ddf-lobby-tile">
            <DdfWebcamCard
              name={`👑 ${hostName ?? "Game Master"}`}
              stream={remoteStreams["host"] ?? null}
              variant="gm"
            />
          </div>
        )}
        {players.map((p) => {
          const isSelf = p.room_player_id === myPlayerId;
          return (
            <div key={p.room_player_id} className="ddf-lobby-tile">
              <DdfWebcamCard
                name={isSelf ? `${p.nickname} (you)` : p.nickname}
                stream={isSelf ? localStream : (remoteStreams[String(p.room_player_id)] ?? null)}
                muted={isSelf}
              />
              <span className={p.is_camera_ready ? "ddf-ready-badge ddf-ready-badge-yes" : "ddf-ready-badge"}>
                {p.is_camera_ready ? "Ready" : "Not ready"}
              </span>
            </div>
          );
        })}
      </DdfWebcamGrid>

      {isGm ? (
        <>
          <DdfGmSettingsPanel
            roundsPerVoting={roundsPerVoting}
            questionTimerSeconds={questionTimerSeconds}
            votingTimerSeconds={votingTimerSeconds}
            language={language}
            couchMode={couchMode}
            safeMode={safeMode}
            disabled={false}
            onSave={(settings) => api.patch(`/api/ddf-rooms/${code}/settings`, settings).then(() => undefined)}
          />
          {startError && <p className="form-error">{startError}</p>}
          <Button variant="grape" size="lg" disabled={!allReady || starting} onClick={() => void handleStart()}>
            {starting ? "Starting…" : "START GAME"}
          </Button>
          {!allReady && <p className="hint">Waiting for at least 2 players, all with a working camera.</p>}
        </>
      ) : (
        <p className="hint">Waiting for {hostName ?? "the Game Master"} to start the game…</p>
      )}
    </div>
    </AvConsentGate>
  );
}
