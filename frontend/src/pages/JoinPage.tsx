import { FormEvent, useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { setPlayerId, setPlayerToken } from "../lib/playerToken";
import { useAuthStore } from "../stores/authStore";

interface JoinResponse {
  id: number;
  nickname: string;
  connection_token: string;
  room_code: string;
}

export function JoinPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();
  const host = useAuthStore((state) => state.host);
  const fetchHost = useAuthStore((state) => state.fetchHost);
  const authStatus = useAuthStore((state) => state.status);

  const [nickname, setNickname] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const isAuthenticated = host !== null;

  useEffect(() => {
    // Unconditional, same reasoning as LobbyPage: this browser might be a
    // logged-in visitor auto-joining as themselves, not just an anonymous
    // one filling in a nickname.
    if (authStatus === "idle") {
      void fetchHost();
    }
  }, [authStatus, fetchHost]);

  async function join(nicknameOverride?: string) {
    if (!code) return;
    setError(null);
    setSubmitting(true);
    try {
      const response = await api.post<JoinResponse>(`/api/rooms/${code}/join`, {
        nickname: nicknameOverride,
      });
      setPlayerToken(response.data.connection_token);
      setPlayerId(response.data.id);
      navigate(`/rooms/${response.data.room_code}/lobby`);
    } catch (err) {
      setError(firstValidationError(err));
      setSubmitting(false);
    }
  }

  useEffect(() => {
    if (authStatus === "ready" && isAuthenticated && !submitting && !error) {
      void join(undefined);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [authStatus, isAuthenticated]);

  function handleSubmit(e: FormEvent) {
    e.preventDefault();
    void join(nickname);
  }

  if (authStatus !== "ready" || (isAuthenticated && submitting && !error)) {
    return (
      <div className="join-page">
        <h1>Join room {code?.toUpperCase()}</h1>
        <p className="hint">
          {isAuthenticated
            ? `Joining as ${host?.username ?? host?.name}…`
            : "Loading…"}
        </p>
      </div>
    );
  }

  return (
    <div className="join-page">
      <h1>Join room {code?.toUpperCase()}</h1>
      {isAuthenticated ? (
        <>
          {error && <p className="form-error">{error}</p>}
          <button type="button" onClick={() => void join(undefined)} disabled={submitting}>
            {submitting ? "Joining…" : `Join as ${host?.username ?? host?.name}`}
          </button>
        </>
      ) : (
        <form onSubmit={handleSubmit}>
          <label>
            Nickname
            <input
              value={nickname}
              onChange={(e) => setNickname(e.target.value)}
              maxLength={20}
              required
            />
          </label>
          {error && <p className="form-error">{error}</p>}
          <button type="submit" disabled={submitting}>
            {submitting ? "Joining…" : "Join"}
          </button>
        </form>
      )}
    </div>
  );
}
