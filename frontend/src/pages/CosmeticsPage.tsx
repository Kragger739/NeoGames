import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";

import type { AvatarData, CosmeticSlot, EquippedCosmetic } from "../lib/avatarData";
import { COSMETIC_SLOTS, cosmeticSvg } from "../lib/cosmetics/registry";
import type { CatalogCosmetic } from "../lib/cosmeticTypes";
import { useAuthStore } from "../stores/authStore";
import { useCosmeticsStore } from "../stores/cosmeticsStore";
import { Avatar } from "../components/ui/Avatar";
import { Button } from "../components/ui/Button";

type Draft = Partial<Record<CosmeticSlot, number | null>>;

function normalize(map: Draft): Record<string, number> {
  const out: Record<string, number> = {};
  for (const [slot, id] of Object.entries(map)) {
    if (id != null) out[slot] = id;
  }
  return out;
}

export function CosmeticsPage() {
  const host = useAuthStore((state) => state.host);
  const { status, data, saving, fetch, save } = useCosmeticsStore();
  const navigate = useNavigate();

  const [draft, setDraft] = useState<Draft>({});
  const [slot, setSlot] = useState<CosmeticSlot>("background");

  useEffect(() => {
    void fetch();
  }, [fetch]);

  useEffect(() => {
    if (data) setDraft({ ...data.equipped });
  }, [data]);

  const byId = useMemo(() => {
    const map = new Map<number, CatalogCosmetic>();
    data?.catalog.forEach((cosmetic) => map.set(cosmetic.id, cosmetic));
    return map;
  }, [data]);

  const previewCosmetics = useMemo(() => {
    const out: Partial<Record<CosmeticSlot, EquippedCosmetic>> = {};
    for (const [entrySlot, id] of Object.entries(draft) as [CosmeticSlot, number | null | undefined][]) {
      if (id == null) continue;
      const cosmetic = byId.get(id);
      if (cosmetic) out[entrySlot] = { key: cosmetic.key, rarity: cosmetic.rarity };
    }
    return out;
  }, [draft, byId]);

  if (!host) return null;

  const previewData: AvatarData = { ...host.avatar, cosmetics: previewCosmetics };
  const dirty =
    JSON.stringify(normalize(draft)) !== JSON.stringify(normalize(data?.equipped ?? {}));
  const slotItems = data?.catalog.filter((cosmetic) => cosmetic.slot === slot) ?? [];

  const currentTier = data?.progress.current_tier ?? 0;
  const nextTier = data?.tiers.find((tier) => tier.tier === currentTier + 1);
  const floorXp = data?.tiers[currentTier - 1]?.threshold ?? 0;
  const progressPct = data && nextTier
    ? Math.min(100, Math.round(((data.progress.xp - floorXp) / (nextTier.threshold - floorXp)) * 100))
    : 100;

  async function handleSave() {
    await save(draft);
    navigate("/profile");
  }

  return (
    <div className="cosmetics-page">
      <p>
        <Link to="/profile">← Profile</Link>
      </p>
      <h1>Customize look</h1>

      <div className="cos-preview">
        <Avatar data={previewData} size="lg" />
      </div>

      {status !== "ready" || !data ? (
        <p className="hint">Loading…</p>
      ) : (
        <>
          <div className="cos-slot-tabs">
            {COSMETIC_SLOTS.map((slotMeta) => (
              <button
                key={slotMeta.id}
                type="button"
                className={`cos-slot-tab${slot === slotMeta.id ? " is-active" : ""}`}
                onClick={() => setSlot(slotMeta.id)}
              >
                {slotMeta.label}
              </button>
            ))}
          </div>

          <div className="cos-chips">
            <button
              type="button"
              className={`cos-chip${draft[slot] == null ? " is-selected" : ""}`}
              onClick={() => setDraft((prev) => ({ ...prev, [slot]: null }))}
            >
              <span className="cos-chip-thumb cos-chip-none">None</span>
            </button>

            {slotItems.map((item) => {
              const Svg = cosmeticSvg(item.key);
              return (
                <button
                  key={item.id}
                  type="button"
                  disabled={!item.owned}
                  title={item.name}
                  className={[
                    "cos-chip",
                    draft[slot] === item.id ? "is-selected" : "",
                    item.owned ? "" : "is-locked",
                  ]
                    .filter(Boolean)
                    .join(" ")}
                  onClick={() => item.owned && setDraft((prev) => ({ ...prev, [slot]: item.id }))}
                >
                  <span className="cos-chip-thumb">{Svg && <Svg className="cos-chip-svg" />}</span>
                  <span className="cos-chip-label">
                    {item.owned ? item.name : `Tier ${item.tier ?? "?"}`}
                  </span>
                </button>
              );
            })}
          </div>

          <div className="cos-actions">
            <Button onClick={() => void handleSave()} disabled={!dirty || saving}>
              {saving ? "Saving…" : "Save"}
            </Button>
            <Button
              variant="ghost"
              disabled={!dirty || saving}
              onClick={() => setDraft({ ...data.equipped })}
            >
              Reset
            </Button>
          </div>

          {data.season && (
            <div className="cos-ladder-wrap">
              <h2>{data.season.name}</h2>
              <div className="cos-progress">
                <div className="cos-progress-track">
                  <div
                    className="cos-progress-fill"
                    style={{ transform: `scaleX(${progressPct / 100})` }}
                  />
                </div>
                <p className="hint">
                  {nextTier
                    ? `${data.progress.xp - floorXp} / ${nextTier.threshold - floorXp} XP to Tier ${nextTier.tier}`
                    : `Season track complete — Tier ${currentTier}`}
                </p>
              </div>

              <div className="cos-ladder">
                {data.tiers.map((tier) => {
                  const Svg = tier.cosmetic ? cosmeticSvg(tier.cosmetic.key) : null;
                  return (
                    <div
                      key={tier.tier}
                      className={[
                        "cos-tier",
                        tier.tier <= currentTier ? "is-reached" : "",
                        tier.tier === currentTier ? "is-current" : "",
                      ]
                        .filter(Boolean)
                        .join(" ")}
                    >
                      <span className="cos-tier-n">Tier {tier.tier}</span>
                      <span className="cos-tier-thumb">{Svg && <Svg className="cos-chip-svg" />}</span>
                      <span className="hint">{tier.owned ? "Unlocked" : `${tier.threshold} XP`}</span>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
