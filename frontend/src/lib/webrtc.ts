import { useEffect, useRef, useState } from "react";

import { useAvConsent } from "../hooks/useAvConsent";
import { getEcho } from "./echo";

/**
 * P2P mesh webcams for "Der Dümmste fliegt" - each browser connects
 * directly to every other browser's camera/mic. Signaling reuses the same
 * room.{code} presence channel already joined for game state (see
 * ddfStore.ts) via Echo's whisper() - a peer-to-peer message relayed by
 * Reverb with no dedicated backend endpoint. Free public STUN only, no
 * TURN: a known, deliberate limitation - peers behind symmetric NAT may
 * fail to connect directly, acceptable for this prototype's scope
 * (same-network/typical home-NAT play).
 *
 * The peer set is reconciled against ddfStore's `members` roster (passed
 * in), NOT the channel's own joining/leaving events. The mesh hook remounts
 * on every lobby <-> in-game route change while ddfStore keeps the presence
 * subscription alive, so a channel.here() would never re-fire and
 * channel.joining() would never see the already-present peers - the mesh
 * would come up blank in-game. Reconciling against the store's roster
 * re-establishes every connection on each remount.
 *
 * Glare avoidance: for any pair, only the lexicographically-smaller
 * String(id) ever sends the offer; the other side asks for one via an
 * "offer-request" signal and always answers. Exactly one offer per pair,
 * no rollback needed. ("host" sorts high, so the GM always answers and
 * players always offer to it.)
 */

const ICE_SERVERS: RTCIceServer[] = [{ urls: "stun:stun.l.google.com:19302" }];

interface PresenceMemberLike {
  id: string | number;
}

interface SignalPayload {
  to: string | number;
  from: string | number;
  type: "offer" | "answer" | "ice" | "offer-request";
  sdp?: RTCSessionDescriptionInit;
  candidate?: RTCIceCandidateInit;
}

export interface WebcamMesh {
  localStream: MediaStream | null;
  remoteStreams: Record<string, MediaStream>;
  mediaError: string | null;
}

/**
 * selfId is supplied by the caller (the literal "host" for the Game
 * Master, or the player's own room_player_id) rather than read off the
 * channel's own member list, matching the same id convention
 * routes/channels.php already establishes for presence payloads.
 */
export function useWebcamMesh(
  code: string | null,
  selfId: string | number | null,
  members: PresenceMemberLike[],
): WebcamMesh {
  const [localStream, setLocalStream] = useState<MediaStream | null>(null);
  const [remoteStreams, setRemoteStreams] = useState<Record<string, MediaStream>>({});
  const [mediaError, setMediaError] = useState<string | null>(null);

  // The DDF pages render an <AvConsentGate> that must be accepted before
  // any device access - don't touch getUserMedia until then.
  const { granted: avConsentGranted } = useAvConsent();

  // Bridges the stable setup effect and the roster-reconcile effect.
  const negotiateRef = useRef<((peerId: string) => void) | null>(null);
  const teardownRef = useRef<((peerId: string) => void) | null>(null);
  const peersRef = useRef<Map<string, RTCPeerConnection> | null>(null);

  useEffect(() => {
    if (!avConsentGranted) return;

    let cancelled = false;

    navigator.mediaDevices
      .getUserMedia({ video: true, audio: true })
      .then((stream) => {
        if (cancelled) {
          stream.getTracks().forEach((track) => track.stop());
          return;
        }
        setLocalStream(stream);
      })
      .catch((err: unknown) => {
        setMediaError(err instanceof Error ? err.message : "Camera/microphone access was denied.");
      });

    return () => {
      cancelled = true;
    };
  }, [avConsentGranted]);

  useEffect(() => {
    return () => {
      localStream?.getTracks().forEach((track) => track.stop());
    };
  }, [localStream]);

  // --- Setup: channel + signaling. Stable while code/selfId/localStream hold. ---
  useEffect(() => {
    if (!code || selfId === null || !localStream) return;

    let live = true;
    const channel = getEcho().join(`room.${code}`);
    const peers = new Map<string, RTCPeerConnection>();
    peersRef.current = peers;

    function teardownPeer(peerId: string) {
      const pc = peers.get(peerId);
      pc?.close();
      peers.delete(peerId);
      if (live) {
        setRemoteStreams((prev) => {
          if (!(peerId in prev)) return prev;
          const next = { ...prev };
          delete next[peerId];
          return next;
        });
      }
    }

    function createPeerConnection(peerId: string): RTCPeerConnection {
      const pc = new RTCPeerConnection({ iceServers: ICE_SERVERS });

      localStream!.getTracks().forEach((track) => pc.addTrack(track, localStream!));

      pc.ontrack = (event) => {
        if (!live) return;
        const [stream] = event.streams;
        if (stream) {
          setRemoteStreams((prev) => ({ ...prev, [peerId]: stream }));
        }
      };

      pc.onicecandidate = (event) => {
        if (event.candidate) {
          channel.whisper("webrtc-signal", {
            to: peerId,
            from: selfId,
            type: "ice",
            candidate: event.candidate.toJSON(),
          });
        }
      };

      pc.onconnectionstatechange = () => {
        if (pc.connectionState === "failed" || pc.connectionState === "closed") {
          teardownPeer(peerId);
        }
      };

      peers.set(peerId, pc);
      return pc;
    }

    function makeOffer(peerId: string) {
      teardownPeer(peerId);
      const pc = createPeerConnection(peerId);
      void pc
        .createOffer()
        .then((offer) => pc.setLocalDescription(offer).then(() => offer))
        .then((offer) => {
          if (live) channel.whisper("webrtc-signal", { to: peerId, from: selfId, type: "offer", sdp: offer });
        });
    }

    function negotiate(peerId: string) {
      if (peers.has(peerId)) return;
      if (String(selfId) < peerId) {
        makeOffer(peerId);
      } else {
        channel.whisper("webrtc-signal", { to: peerId, from: selfId, type: "offer-request" });
      }
    }

    negotiateRef.current = negotiate;
    teardownRef.current = teardownPeer;

    channel.listenForWhisper("webrtc-signal", (payload: SignalPayload) => {
      if (!live || String(payload.to) !== String(selfId)) return;

      const peerId = String(payload.from);

      if (payload.type === "offer-request") {
        makeOffer(peerId);
        return;
      }

      if (payload.type === "offer" && payload.sdp) {
        teardownPeer(peerId);
        const pc = createPeerConnection(peerId);
        void pc
          .setRemoteDescription(new RTCSessionDescription(payload.sdp))
          .then(() => pc.createAnswer())
          .then((answer) => pc.setLocalDescription(answer).then(() => answer))
          .then((answer) => {
            if (live) channel.whisper("webrtc-signal", { to: peerId, from: selfId, type: "answer", sdp: answer });
          });
        return;
      }

      const pc = peers.get(peerId);
      if (!pc) return;

      if (payload.type === "answer" && payload.sdp) {
        void pc.setRemoteDescription(new RTCSessionDescription(payload.sdp));
      } else if (payload.type === "ice" && payload.candidate) {
        void pc.addIceCandidate(new RTCIceCandidate(payload.candidate));
      }
    });

    return () => {
      live = false;
      channel.stopListeningForWhisper("webrtc-signal");
      peers.forEach((pc) => pc.close());
      peers.clear();
      peersRef.current = null;
      negotiateRef.current = null;
      teardownRef.current = null;
      setRemoteStreams({});
    };
  }, [code, selfId, localStream]);

  // --- Reconcile the peer set against the store's presence roster. ---
  useEffect(() => {
    const negotiate = negotiateRef.current;
    const teardownPeer = teardownRef.current;
    const peers = peersRef.current;
    if (!negotiate || !teardownPeer || !peers || selfId === null) return;

    const wanted = new Set(
      members.map((m) => String(m.id)).filter((id) => id !== String(selfId)),
    );

    for (const id of wanted) {
      if (!peers.has(id)) negotiate(id);
    }
    for (const id of [...peers.keys()]) {
      if (!wanted.has(id)) teardownPeer(id);
    }
  }, [members, selfId, localStream, code]);

  return { localStream, remoteStreams, mediaError };
}
