import { useEffect } from "react";
import { useNavigate, useParams } from "react-router-dom";

import { getPlayerId } from "../lib/playerToken";
import { useWebcamMesh } from "../lib/webrtc";
import { useDdfStore } from "../stores/ddfStore";
import { AvConsentGate } from "../components/ddf/AvConsentGate";
import { DdfGameView } from "../components/ddf/DdfGameView";

export function DdfPlayOverlayPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();
  const myPlayerId = getPlayerId();

  const connect = useDdfStore((s) => s.connect);
  const leaveRoom = useDdfStore((s) => s.leaveRoom);
  const state = useDdfStore((s) => s.state);
  const caughtUp = useDdfStore((s) => s.caughtUp);
  const members = useDdfStore((s) => s.members);

  const { localStream, remoteStreams } = useWebcamMesh(code ?? null, myPlayerId, members);

  useEffect(() => {
    if (!code) return;
    connect(code);
    return () => leaveRoom();
  }, [code, connect, leaveRoom]);

  useEffect(() => {
    if (caughtUp && state === "lobby") {
      navigate(`/ddf-rooms/${code}/lobby`);
    }
  }, [caughtUp, state, code, navigate]);

  if (!code || myPlayerId === null) return null;

  return (
    <AvConsentGate>
      <DdfGameView code={code} viewerId={myPlayerId} isGm={false} localStream={localStream} remoteStreams={remoteStreams} />
    </AvConsentGate>
  );
}
