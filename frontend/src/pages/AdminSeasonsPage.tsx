import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";

import { cosmeticSvg } from "../lib/cosmetics/registry";
import {
  type CosmeticLibItem,
  type SeasonRow,
  type SeasonTierRow,
  useAdminSeasonsStore,
} from "../stores/adminSeasonsStore";
import { Button } from "../components/ui/Button";

function CosmeticThumb({ item, size = 40 }: { item: CosmeticLibItem | undefined; size?: number }) {
  if (!item) return <span className="hint">—</span>;
  const style = { width: size, height: size, objectFit: "contain" as const };
  if (item.image_url) return <img src={item.image_url} alt="" style={style} />;
  const Svg = cosmeticSvg(item.key);
  return Svg ? <Svg className="cos-chip-svg" /> : <span className="hint">{item.name}</span>;
}

const todayIso = () => new Date().toISOString().slice(0, 10);

export function AdminSeasonsPage() {
  const {
    seasons,
    cosmetics,
    slots,
    rarities,
    sources,
    status,
    error,
    fetch,
    createSeason,
    updateSeason,
    deleteSeason,
    saveTiers,
    createCosmetic,
    updateCosmetic,
    deleteCosmetic,
  } = useAdminSeasonsStore();

  useEffect(() => {
    void fetch();
  }, [fetch]);

  // --- create season form ---
  const [newName, setNewName] = useState("");
  const [newStart, setNewStart] = useState(todayIso());
  const [newLength, setNewLength] = useState(42);
  const [cloneFrom, setCloneFrom] = useState<number | "">("");

  const newEnd = useMemo(() => {
    const d = new Date(newStart);
    if (Number.isNaN(d.getTime())) return "";
    d.setDate(d.getDate() + Number(newLength || 0));
    return d.toISOString().slice(0, 10);
  }, [newStart, newLength]);

  async function handleCreateSeason(e: React.FormEvent) {
    e.preventDefault();
    await createSeason({
      name: newName.trim(),
      starts_at: newStart,
      length_days: Number(newLength),
      clone_from: cloneFrom === "" ? null : Number(cloneFrom),
    });
    setNewName("");
  }

  // --- edit season ---
  const [editSeason, setEditSeason] = useState<SeasonRow | null>(null);

  // --- ladder editor ---
  const [ladderSeasonId, setLadderSeasonId] = useState<number | null>(null);
  const ladderSeason = seasons.find((s) => s.id === ladderSeasonId) ?? null;
  const [ladder, setLadder] = useState<SeasonTierRow[]>([]);

  useEffect(() => {
    if (ladderSeason) setLadder(ladderSeason.tiers.map((t) => ({ ...t })));
  }, [ladderSeason]);

  function setTier(i: number, patch: Partial<SeasonTierRow>) {
    setLadder((rows) => rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
  }

  // --- cosmetic form ---
  const emptyCosmetic = { id: 0, name: "", slot: slots[0] ?? "frame", rarity: "common", source: "track", season_id: "" as number | "" };
  const [cosForm, setCosForm] = useState(emptyCosmetic);
  const [cosFile, setCosFile] = useState<File | null>(null);
  const [cosPreview, setCosPreview] = useState<string | null>(null);

  function resetCosForm() {
    setCosForm({ ...emptyCosmetic, slot: slots[0] ?? "frame" });
    setCosFile(null);
    setCosPreview(null);
  }

  function editCosmetic(c: CosmeticLibItem) {
    setCosForm({
      id: c.id,
      name: c.name,
      slot: c.slot,
      rarity: c.rarity,
      source: c.source,
      season_id: c.season_id ?? "",
    });
    setCosFile(null);
    setCosPreview(c.image_url);
  }

  async function handleCosSubmit(e: React.FormEvent) {
    e.preventDefault();
    const fd = new FormData();
    fd.append("name", cosForm.name.trim());
    fd.append("slot", cosForm.slot);
    fd.append("rarity", cosForm.rarity);
    fd.append("source", cosForm.source);
    if (cosForm.season_id !== "") fd.append("season_id", String(cosForm.season_id));
    if (cosFile) fd.append("image", cosFile);

    if (cosForm.id) await updateCosmetic(cosForm.id, fd);
    else await createCosmetic(fd);
    resetCosForm();
  }

  const fmtDate = (iso: string) => new Date(iso).toLocaleDateString();

  return (
    <div className="admin-page">
      <p>
        <Link to="/admin">← Users</Link>
        {"  ·  "}
        <Link to="/admin/song-playlists">Song playlists</Link>
        {"  ·  "}
        <Link to="/admin/unlocks">Unlocks &amp; Daily</Link>
        {"  ·  "}
        <Link to="/">Home</Link>
      </p>
      <h1>Seasons &amp; Battlepass</h1>

      {error && <p className="form-error">{error}</p>}
      {status !== "ready" && <p className="hint">Loading…</p>}

      {/* ---- Seasons ---- */}
      <h2>Seasons</h2>
      <ul className="player-list">
        {seasons.map((s) => (
          <li key={s.id}>
            <span className="friend-name">
              {s.name} {s.is_current && <strong>· CURRENT</strong>}
              <span className="hint">
                {" "}
                {fmtDate(s.starts_at)} – {fmtDate(s.ends_at)} · {s.tier_count} tiers · {s.player_count} players
              </span>
            </span>
            <span>
              <Button variant="ghost" onClick={() => setEditSeason(s)}>
                Edit
              </Button>
              <Button
                variant="ghost"
                onClick={() => {
                  if (confirm(`Delete "${s.name}"? This wipes its progress and ladder.`)) {
                    void deleteSeason(s.id);
                  }
                }}
              >
                Delete
              </Button>
            </span>
          </li>
        ))}
      </ul>

      {editSeason && (
        <form
          className="admin-form"
          onSubmit={(e) => {
            e.preventDefault();
            void updateSeason(editSeason.id, {
              name: editSeason.name,
              starts_at: editSeason.starts_at.slice(0, 10),
              ends_at: editSeason.ends_at.slice(0, 10),
            }).then(() => setEditSeason(null));
          }}
        >
          <h3>Edit {editSeason.name}</h3>
          <label>
            Name
            <input value={editSeason.name} onChange={(e) => setEditSeason({ ...editSeason, name: e.target.value })} />
          </label>
          <label>
            Starts
            <input
              type="date"
              value={editSeason.starts_at.slice(0, 10)}
              onChange={(e) => setEditSeason({ ...editSeason, starts_at: e.target.value })}
            />
          </label>
          <label>
            Ends
            <input
              type="date"
              value={editSeason.ends_at.slice(0, 10)}
              onChange={(e) => setEditSeason({ ...editSeason, ends_at: e.target.value })}
            />
          </label>
          <div className="cos-actions">
            <Button type="submit">Save</Button>
            <Button type="button" variant="ghost" onClick={() => setEditSeason(null)}>
              Cancel
            </Button>
          </div>
        </form>
      )}

      <form className="admin-form" onSubmit={handleCreateSeason}>
        <h3>New season</h3>
        <label>
          Name
          <input value={newName} onChange={(e) => setNewName(e.target.value)} required placeholder="Season 2" />
        </label>
        <label>
          Start date
          <input type="date" value={newStart} onChange={(e) => setNewStart(e.target.value)} />
        </label>
        <label>
          Length (days)
          <input
            type="number"
            min={1}
            max={365}
            value={newLength}
            onChange={(e) => setNewLength(Number(e.target.value))}
          />
        </label>
        <p className="hint">Ends {newEnd || "—"}</p>
        <label>
          Clone ladder from
          <select value={cloneFrom} onChange={(e) => setCloneFrom(e.target.value === "" ? "" : Number(e.target.value))}>
            <option value="">— none —</option>
            {seasons.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name} ({s.tier_count} tiers)
              </option>
            ))}
          </select>
        </label>
        <Button type="submit" disabled={!newName.trim()}>
          Create season
        </Button>
      </form>

      {/* ---- Battlepass ladder ---- */}
      <h2>Battlepass ladder</h2>
      <label className="admin-check">
        Season
        <select
          value={ladderSeasonId ?? ""}
          onChange={(e) => setLadderSeasonId(e.target.value === "" ? null : Number(e.target.value))}
        >
          <option value="">— pick a season —</option>
          {seasons.map((s) => (
            <option key={s.id} value={s.id}>
              {s.name}
            </option>
          ))}
        </select>
      </label>

      {ladderSeason && (
        <>
          <ul className="player-list">
            {ladder.map((row, i) => (
              <li key={i}>
                <span className="friend-name">Tier {i + 1}</span>
                <label className="admin-check">
                  XP
                  <input
                    type="number"
                    min={1}
                    value={row.xp_threshold}
                    onChange={(e) => setTier(i, { xp_threshold: Number(e.target.value) })}
                  />
                </label>
                <label className="admin-check">
                  Free
                  <select
                    value={row.free_cosmetic_id ?? ""}
                    onChange={(e) =>
                      setTier(i, { free_cosmetic_id: e.target.value === "" ? null : Number(e.target.value) })
                    }
                  >
                    <option value="">— none —</option>
                    {cosmetics.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name} ({c.slot})
                      </option>
                    ))}
                  </select>
                </label>
                <label className="admin-check">
                  Premium
                  <select
                    value={row.premium_cosmetic_id ?? ""}
                    onChange={(e) =>
                      setTier(i, { premium_cosmetic_id: e.target.value === "" ? null : Number(e.target.value) })
                    }
                  >
                    <option value="">— none —</option>
                    {cosmetics.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name} ({c.slot})
                      </option>
                    ))}
                  </select>
                </label>
              </li>
            ))}
          </ul>
          <div className="cos-actions">
            <Button
              variant="ghost"
              onClick={() =>
                setLadder((rows) => [
                  ...rows,
                  {
                    tier: rows.length + 1,
                    xp_threshold: (rows.at(-1)?.xp_threshold ?? 0) + 100,
                    free_cosmetic_id: null,
                    premium_cosmetic_id: null,
                  },
                ])
              }
            >
              Add tier
            </Button>
            <Button variant="ghost" disabled={ladder.length === 0} onClick={() => setLadder((r) => r.slice(0, -1))}>
              Remove last
            </Button>
            <Button onClick={() => void saveTiers(ladderSeason.id, ladder)}>Save ladder</Button>
          </div>
        </>
      )}

      {/* ---- Cosmetic library ---- */}
      <h2>Cosmetic library</h2>
      <ul className="player-list">
        {cosmetics.map((c) => (
          <li key={c.id}>
            <span className="cos-tier-thumb">
              <CosmeticThumb item={c} />
            </span>
            <span className="friend-name">
              {c.name}
              <span className="hint">
                {" "}
                {c.slot} · {c.rarity} · {c.source}
                {c.season_id ? ` · season ${c.season_id}` : ""}
                {c.has_registry_svg ? " · registry" : " · uploaded"}
              </span>
            </span>
            <span>
              <Button variant="ghost" onClick={() => editCosmetic(c)}>
                Edit
              </Button>
              <Button
                variant="ghost"
                onClick={() => {
                  if (confirm(`Delete "${c.name}"?`)) void deleteCosmetic(c.id);
                }}
              >
                Delete
              </Button>
            </span>
          </li>
        ))}
      </ul>

      <form className="admin-form" onSubmit={handleCosSubmit}>
        <h3>{cosForm.id ? `Edit ${cosForm.name}` : "New cosmetic"}</h3>
        <label>
          Name
          <input value={cosForm.name} onChange={(e) => setCosForm({ ...cosForm, name: e.target.value })} required />
        </label>
        <label>
          Slot
          <select value={cosForm.slot} onChange={(e) => setCosForm({ ...cosForm, slot: e.target.value })}>
            {slots.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </label>
        <label>
          Rarity
          <select value={cosForm.rarity} onChange={(e) => setCosForm({ ...cosForm, rarity: e.target.value })}>
            {rarities.map((r) => (
              <option key={r} value={r}>
                {r}
              </option>
            ))}
          </select>
        </label>
        <label>
          Source
          <select value={cosForm.source} onChange={(e) => setCosForm({ ...cosForm, source: e.target.value })}>
            {sources.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </label>
        <label>
          Season
          <select
            value={cosForm.season_id}
            onChange={(e) =>
              setCosForm({ ...cosForm, season_id: e.target.value === "" ? "" : Number(e.target.value) })
            }
          >
            <option value="">— none —</option>
            {seasons.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>
        </label>
        <label>
          Image (PNG / WebP, optional)
          <input
            type="file"
            accept="image/png,image/webp"
            onChange={(e) => {
              const f = e.target.files?.[0] ?? null;
              setCosFile(f);
              setCosPreview(f ? URL.createObjectURL(f) : null);
            }}
          />
        </label>
        {cosPreview && <img src={cosPreview} alt="" style={{ width: 64, height: 64, objectFit: "contain" }} />}
        <div className="cos-actions">
          <Button type="submit" disabled={!cosForm.name.trim()}>
            {cosForm.id ? "Save cosmetic" : "Create cosmetic"}
          </Button>
          {cosForm.id !== 0 && (
            <Button type="button" variant="ghost" onClick={resetCosForm}>
              Cancel
            </Button>
          )}
        </div>
      </form>
    </div>
  );
}
