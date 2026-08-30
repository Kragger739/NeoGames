import { type FormEvent, useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Copy, ListMusic, ListPlus, Trash2 } from "lucide-react";

import { firstValidationError } from "../lib/errors";
import type { DatasetLanguage, DatasetType } from "../lib/workshopTypes";
import { useWorkshopStore } from "../stores/workshopStore";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

function relativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  const days = Math.floor(diff / 86_400_000);
  if (days >= 1) return days === 1 ? "yesterday" : `${days}d ago`;
  const hours = Math.floor(diff / 3_600_000);
  if (hours >= 1) return `${hours}h ago`;
  return "just now";
}

export function WorkshopPage() {
  const navigate = useNavigate();
  const status = useWorkshopStore((state) => state.status);
  const index = useWorkshopStore((state) => state.index);
  const fetchIndex = useWorkshopStore((state) => state.fetchIndex);
  const create = useWorkshopStore((state) => state.create);
  const duplicate = useWorkshopStore((state) => state.duplicate);
  const remove = useWorkshopStore((state) => state.remove);
  const saving = useWorkshopStore((state) => state.saving);

  const [newType, setNewType] = useState<DatasetType | null>(null);
  const [name, setName] = useState("");
  const [language, setLanguage] = useState<DatasetLanguage>("en");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (status === "idle") void fetchIndex();
  }, [status, fetchIndex]);

  function startCreate(type: DatasetType) {
    setNewType(type);
    setName("");
    setLanguage("en");
    setError(null);
  }

  async function handleCreate(e: FormEvent) {
    e.preventDefault();
    if (!newType) return;
    setError(null);
    try {
      const dataset = await create({
        name: name.trim(),
        type: newType,
        language: newType === "ddf" ? language : undefined,
      });
      navigate(`/workshop/${dataset.id}`);
    } catch (err) {
      setError(firstValidationError(err));
    }
  }

  async function handleDuplicate(id: number) {
    const copy = await duplicate(id);
    navigate(`/workshop/${copy.id}`);
  }

  return (
    <div className="workshop-page">
      <p>
        <Link to="/">← Home</Link>
      </p>
      <h1>Workshop</h1>
      <p className="hint">Build your own question sets and Songle playlists, then use them in a game.</p>

      <div className="wk-create">
        <Button variant="grape" onClick={() => startCreate("ddf")}>
          <ListPlus size={18} strokeWidth={2.25} /> Create question dataset
        </Button>
        <Button variant="turquoise" onClick={() => startCreate("songle")}>
          <ListMusic size={18} strokeWidth={2.25} /> Create Songle dataset
        </Button>
      </div>

      {newType && (
        <form className="wk-create-form" onSubmit={handleCreate}>
          <label>
            Name
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={newType === "ddf" ? "e.g. Family Quiz" : "e.g. 2000s Party"}
              maxLength={80}
              required
              autoFocus
            />
          </label>
          {newType === "ddf" && (
            <label>
              Language
              <select value={language} onChange={(e) => setLanguage(e.target.value as DatasetLanguage)}>
                <option value="en">English</option>
                <option value="de">German</option>
              </select>
            </label>
          )}
          {error && <p className="form-error">{error}</p>}
          <div className="wk-create-actions">
            <Button type="submit" disabled={saving}>
              {saving ? "Creating…" : "Create"}
            </Button>
            <Button variant="ghost" type="button" onClick={() => setNewType(null)}>
              Cancel
            </Button>
          </div>
        </form>
      )}

      <h2>My datasets</h2>
      {status !== "ready" ? (
        <p className="hint">Loading…</p>
      ) : index.mine.length === 0 ? (
        <p className="hint">Nothing yet — create a question set or a Songle playlist above.</p>
      ) : (
        <ul className="wk-list">
          {index.mine.map((dataset) => (
            <li key={dataset.id}>
              <span className="wk-info">
                <span className="wk-name">{dataset.name}</span>
                <span className="wk-meta">
                  <Badge tone={dataset.type === "ddf" ? "grape" : "turquoise"}>
                    {dataset.type === "ddf" ? "Questions" : "Songle"}
                  </Badge>
                  <span className="hint">
                    {dataset.item_count} {dataset.type === "ddf" ? "questions" : "tracks"} ·{" "}
                    {dataset.visibility} · {relativeTime(dataset.updated_at)}
                  </span>
                </span>
              </span>
              <span className="wk-actions">
                <Button onClick={() => navigate(`/workshop/${dataset.id}`)}>Edit</Button>
                <Button variant="ghost" onClick={() => void handleDuplicate(dataset.id)} aria-label="Duplicate">
                  <Copy size={16} strokeWidth={2.25} />
                </Button>
                <Button
                  variant="danger"
                  onClick={() => {
                    if (confirm(`Delete "${dataset.name}"? This can’t be undone.`)) void remove(dataset.id);
                  }}
                  aria-label="Delete"
                >
                  <Trash2 size={16} strokeWidth={2.25} />
                </Button>
              </span>
            </li>
          ))}
        </ul>
      )}

      {index.community.length > 0 && (
        <>
          <h2>Community datasets</h2>
          <ul className="wk-list">
            {index.community.map((dataset) => (
              <li key={dataset.id}>
                <span className="wk-info">
                  <span className="wk-name">{dataset.name}</span>
                  <span className="wk-meta">
                    <Badge tone={dataset.type === "ddf" ? "grape" : "turquoise"}>
                      {dataset.type === "ddf" ? "Questions" : "Songle"}
                    </Badge>
                    <span className="hint">
                      {dataset.item_count} {dataset.type === "ddf" ? "questions" : "tracks"} · by{" "}
                      {dataset.owner_username ?? "someone"}
                    </span>
                  </span>
                </span>
                <span className="wk-actions">
                  <Button onClick={() => void handleDuplicate(dataset.id)}>
                    <Copy size={16} strokeWidth={2.25} /> Copy to mine
                  </Button>
                </span>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}
