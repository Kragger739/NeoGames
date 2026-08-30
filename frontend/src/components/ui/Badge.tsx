import type { HTMLAttributes } from "react";

interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  tone?: "coral" | "turquoise" | "sunflower" | "grape" | "gold" | "silver" | "bronze";
}

/** Pill-shaped tag — player levels, rank medals, online status, tier labels. */
export function Badge({ tone = "coral", className, ...props }: BadgeProps) {
  return <span className={["badge", `badge-${tone}`, className].filter(Boolean).join(" ")} {...props} />;
}
