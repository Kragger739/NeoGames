/**
 * Stays inside DESIGN.md's One Accent Rule rather than becoming an
 * exception to it: online is a solid Late-Night-Violet dot, offline is a
 * hollow Curtain-Line ring - presence/absence of the one accent, the same
 * way the rest of the system marks "the thing that matters," not a new
 * green/red hue.
 */
export function OnlineStatusDot({ online }: { online: boolean }) {
  return (
    <span
      className={online ? "online-dot online-dot-on" : "online-dot"}
      aria-label={online ? "Online" : "Offline"}
      title={online ? "Online" : "Offline"}
    />
  );
}
