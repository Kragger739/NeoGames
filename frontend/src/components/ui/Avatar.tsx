import type { CSSProperties } from "react";

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
    </span>
  );
}

function CosmeticLayer({ slot, data }: { slot: CosmeticSlot; data: AvatarData }) {
  const key = data.cosmetics[slot]?.key;
  const Svg = key ? COSMETIC_SVGS[key] : undefined;
  if (!Svg) return null;

  const style: CSSProperties = { zIndex: SLOT_Z[slot] };

  return (
    <span className="avatar-layer" style={style}>
      <Svg className="avatar-layer-svg" />
    </span>
  );
}
