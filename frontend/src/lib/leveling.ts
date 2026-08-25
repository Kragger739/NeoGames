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
