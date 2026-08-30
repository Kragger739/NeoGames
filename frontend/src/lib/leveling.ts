// Mirrors backend/config/leveling.php's triangular curve: XP to *reach*
// level N is coefficient * N * (N - 1). Kept in sync manually - retune both
// sides together if the curve ever changes.
const LEVEL_CURVE_COEFFICIENT = 50;

function xpForLevel(level: number): number {
  return LEVEL_CURVE_COEFFICIENT * level * (level - 1);
}

export interface LevelProgress {
  xpIntoLevel: number;
  xpForNextLevel: number;
  percent: number;
}

export function levelProgress(xp: number, level: number): LevelProgress {
  const floor = xpForLevel(level);
  const ceiling = xpForLevel(level + 1);
  const xpIntoLevel = xp - floor;
  const xpForNextLevel = ceiling - floor;

  return {
    xpIntoLevel,
    xpForNextLevel,
    percent: Math.min(100, Math.round((xpIntoLevel / xpForNextLevel) * 100)),
  };
}

// Highest-tier-first so the first match wins. Purely cosmetic - retuning
// this doesn't need a backend change, it's a pure function of the level
// already known client-side.
const AVATAR_FRAME_TIERS: { minLevel: number; frame: string }[] = [
  { minLevel: 30, frame: "gold" },
  { minLevel: 15, frame: "silver" },
  { minLevel: 5, frame: "bronze" },
];

export function avatarFrameForLevel(level: number): string | null {
  return AVATAR_FRAME_TIERS.find((tier) => level >= tier.minLevel)?.frame ?? null;
}

/**
 * Builds the full className for an avatar preview element (real photo or
 * the placeholder square) - shared by ProfilePage's 96px preview and
 * Dashboard's 48px one, so the frame-tier logic lives in exactly one place.
 */
export function avatarPreviewClassName(level: number, small = false): string {
  const frame = avatarFrameForLevel(level);
  const classes = ["avatar-preview"];
  if (small) classes.push("avatar-preview-small");
  if (frame) classes.push(`avatar-frame-${frame}`);
  return classes.join(" ");
}
