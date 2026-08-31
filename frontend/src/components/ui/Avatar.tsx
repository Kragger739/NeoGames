import type { CSSProperties } from "react";
import { ShieldCheck } from "lucide-react";

import type { AvatarData, CosmeticSlot } from "../../lib/avatarData";
import { COSMETIC_SVGS, SLOT_Z } from "../../lib/cosmetics/registry";
import { avatarFrameForLevel } from "../../lib/leveling";

// The one identity element, used on every surface a user appears. Composites
// the photo (or the silhouette placeholder) with whatever cosmetics are
// equipped, as stacked SVG layers. A `frame` cosmetic replaces the
// level-tier ring; with no frame equipped the ring still shows.

type AvatarSize = "xs" | "sm" | "md" | "lg";

interface AvatarProps {
  data: AvatarData;
  size?: AvatarSize;
  /** List surfaces pass false so the animated `effect` layer never runs 10-50x. */
  animated?: boolean;
  className?: string;
}

export function Avatar({ data, size = "sm", animated = true, className }: AvatarProps) {
  const tier = data.cosmetics.frame ? null : avatarFrameForLevel(data.level);

  const boxClass = ["avatar", `avatar-${size}`, tier ? `avatar-frame-${tier}` : "", className]
    .filter(Boolean)
    .join(" ");

  return (
    <span className={boxClass}>
      <CosmeticLayer slot="background" data={data} />

      {data.avatar_url ? (
        <img className="avatar-photo" src={data.avatar_url} alt="" />
      ) : (
        <span className="avatar-photo art-placeholder" aria-hidden="true" />
      )}

      <CosmeticLayer slot="frame" data={data} />
      <CosmeticLayer slot="accessory" data={data} />
      <CosmeticLayer slot="hat" data={data} />
      <CosmeticLayer slot="badge" data={data} />
      {animated && <CosmeticLayer slot="effect" data={data} />}

      {data.is_admin && (
        <span className="avatar-admin-badge" aria-label="Admin" title="Admin">
          <ShieldCheck className="avatar-admin-badge-icon" strokeWidth={2.5} />
        </span>
      )}
    </span>
  );
}

function CosmeticLayer({ slot, data }: { slot: CosmeticSlot; data: AvatarData }) {
  const cosmetic = data.cosmetics[slot];
  if (!cosmetic) return null;

  const style: CSSProperties = { zIndex: SLOT_Z[slot] };

  // An admin-uploaded cosmetic renders from its image; otherwise fall back to
  // the hand-authored SVG in the registry keyed by `key`.
  if (cosmetic.image_url) {
    return (
      <span className="avatar-layer" style={style}>
        <img className="avatar-layer-svg" src={cosmetic.image_url} alt="" aria-hidden="true" />
      </span>
    );
  }

  const Svg = COSMETIC_SVGS[cosmetic.key];
  if (!Svg) return null;

  return (
    <span className="avatar-layer" style={style}>
      <Svg className="avatar-layer-svg" />
    </span>
  );
}
