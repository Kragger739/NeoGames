import { type FormEvent, useEffect, useState } from "react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import type { FriendSearchResult } from "../lib/friendTypes";
import { useFriendsStore } from "../stores/friendsStore";
import { Avatar } from "./ui/Avatar";
import { Button } from "./ui/Button";

const DEBOUNCE_MS = 300;
const MIN_QUERY_LENGTH = 2;

function capitalize(name: string) {
  return name.charAt(0).toUpperCase() + name.slice(1);
}

export function FriendSearch() {
  const sendRequest = useFriendsStore((state) => state.sendRequest);

  const [query, setQuery] = useState("");
  const [results, setResults] = useState<FriendSearchResult[]>([]);
  const [open, setOpen] = useState(false);
  const [searching, setSearching] = useState(false);
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (query.trim().length < MIN_QUERY_LENGTH) {
      setResults([]);
      setSearching(false);
      return;
    }

    // Cancel any still-in-flight search from the previous keystroke so a
    // burst of typing never lands results out of order.
    const controller = new AbortController();

    const handle = setTimeout(() => {
      setSearching(true);
      api
        .get<{ results: FriendSearchResult[] }>("/api/friends/search", {
          params: { q: query },
          signal: controller.signal,
        })
        .then((response) => setResults(response.data.results))
        .catch((err) => {
          if (err.code !== "ERR_CANCELED") setResults([]);
        })
        .finally(() => setSearching(false));
    }, DEBOUNCE_MS);

    return () => {
      clearTimeout(handle);
      controller.abort();
    };
  }, [query]);

  async function pick(user: FriendSearchResult) {
    setError(null);
    setPendingId(user.id);
    try {
      await sendRequest(user.username);
      setQuery("");
      setResults([]);
      setOpen(false);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setPendingId(null);
    }
  }

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    const term = query.trim();
    if (!term) return;
    setError(null);
    setPendingId(-1);
    try {
      await sendRequest(term);
      setQuery("");
      setResults([]);
      setOpen(false);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setPendingId(null);
    }
  }

  return (
    <div className="friend-search">
      <form onSubmit={handleSubmit}>
        <label>
          Add a friend by username
          <input
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setOpen(true);
            }}
            onFocus={() => setOpen(true)}
            onBlur={() => setTimeout(() => setOpen(false), 150)}
            placeholder="Start typing a username…"
            autoComplete="off"
          />
        </label>
        {error && <p className="form-error">{error}</p>}
        <Button type="submit" disabled={pendingId !== null || !query.trim()}>
          {pendingId === -1 ? "Sending…" : "Send request"}
        </Button>
      </form>

      {open &&
        searching &&
        results.length === 0 &&
        query.trim().length >= MIN_QUERY_LENGTH && (
          <ul className="friend-suggestions">
            <li className="hint">Searching…</li>
          </ul>
        )}

      {open && results.length > 0 && (
        <ul className="friend-suggestions">
          {results.map((user) => (
            <li key={user.id}>
              <Avatar data={user.avatar} size="xs" animated={false} />
              <button
                type="button"
                className="suggestion-label"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => void pick(user)}
                disabled={pendingId !== null}
              >
                <span>{capitalize(user.username)}</span>
                <span className="hint">
                  {pendingId === user.id ? "Sending…" : `Level ${user.level}`}
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
