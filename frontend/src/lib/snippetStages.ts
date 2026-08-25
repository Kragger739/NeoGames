// Mirrors backend/app/Support/SnippetStage.php's SEQUENCE - kept in sync
// manually, same pattern as leveling.ts mirroring the XP curve.
export const SNIPPET_STAGE_SEQUENCE = [0.1, 0.5, 1.0, 5.0, 15.0];

export const MAX_SNIPPET_SECONDS =
  SNIPPET_STAGE_SEQUENCE[SNIPPET_STAGE_SEQUENCE.length - 1];

export interface StageSegment {
  stage: number;
  startPct: number;
  widthPct: number;
}

/**
 * Turns the stage sequence into a timeline: each segment's width is
 * proportional to how long that tier actually lasts (0.1s tiny, the
 * 5s->15s tier big), instead of every tier getting equal screen space.
 */
export function getStageSegments(): StageSegment[] {
  const bounds = [0, ...SNIPPET_STAGE_SEQUENCE];

  return SNIPPET_STAGE_SEQUENCE.map((stage, i) => ({
    stage,
    startPct: (bounds[i] / MAX_SNIPPET_SECONDS) * 100,
    widthPct: ((bounds[i + 1] - bounds[i]) / MAX_SNIPPET_SECONDS) * 100,
  }));
}
