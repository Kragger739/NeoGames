import { useEffect, useState } from "react";

import { useAdminDubPacksStore } from "../stores/adminDubPacksStore";
import { AdminNav } from "../components/AdminNav";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

export function AdminDubPacksPage() {
  const { packs, status, error, fetch, approve, reject } = useAdminDubPacksStore();
  const [rejectingId, setRejectingId] = useState<number | null>(null);
  const [note, setNote] = useState("");

  useEffect(() => {
    void fetch();
  }, [fetch]);

  return (
    <div className="admin-page">
      <AdminNav />
      <h1>Dub packs</h1>
      <p className="hint">
        User-authored Dub Together packs awaiting review. Approving a pack lets everyone play it;
        rejecting keeps it out with a note the owner sees.
      </p>

      {error && <p className="form-error">{error}</p>}

      {status !== "ready" && packs.length === 0 ? (
        <p className="hint">Loading…</p>
      ) : packs.length === 0 ? (
        <p className="hint">Nothing to review.</p>
      ) : (
        <ul className="player-list">
          {packs.map((pack) => {
            const tone =
              pack.review_status === "approved"
                ? "turquoise"
                : pack.review_status === "rejected"
                  ? "coral"
                  : "sunflower";
            return (
              <li key={pack.id}>
                <span className="friend-name">
                  {pack.name}
                  <span className="hint">
                    <Badge tone={tone}>{pack.review_status}</Badge> by {pack.owner_username ?? "someone"} ·{" "}
                    {pack.ready_clip_count}/{pack.clip_count} clips ready
                    {pack.review_note ? ` — “${pack.review_note}”` : ""}
                  </span>
                  <span className="hint">{pack.clips.map((c) => `${c.title} (${c.status})`).join(", ")}</span>
                </span>
                <span>
                  {pack.review_status !== "approved" && (
                    <Button variant="ghost" onClick={() => void approve(pack.id)}>
                      Approve
                    </Button>
                  )}
                  {pack.review_status !== "rejected" && (
                    <Button
                      variant="ghost"
                      onClick={() => {
                        setRejectingId(pack.id);
                        setNote("");
                      }}
                    >
                      Reject
                    </Button>
                  )}
                </span>
                {rejectingId === pack.id && (
                  <form
                    className="admin-form"
                    onSubmit={(e) => {
                      e.preventDefault();
                      void reject(pack.id, note.trim()).then(() => setRejectingId(null));
                    }}
                  >
                    <label>
                      Reason (shown to the owner)
                      <input value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} />
                    </label>
                    <div className="admin-sync-bar">
                      <Button type="submit" variant="danger">
                        Reject pack
                      </Button>
                      <Button type="button" variant="ghost" onClick={() => setRejectingId(null)}>
                        Cancel
                      </Button>
                    </div>
                  </form>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
