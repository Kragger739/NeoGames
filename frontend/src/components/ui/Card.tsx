import type { HTMLAttributes } from "react";

interface CardProps extends HTMLAttributes<HTMLDivElement> {
  tint?: "coral" | "turquoise" | "sunflower" | "grape" | "bubblegum";
  interactive?: boolean;
}

/**
 * The one lifted-surface container in the system — white on cream, a
 * colored glossy shadow, 24px radius. `tint` washes the fill toward one
 * candy hue for selectable/categorized cards (mode pickers, game tiles);
 * `interactive` adds the hover lift for anything clickable.
 */
export function Card({ tint, interactive, className, ...props }: CardProps) {
  const classes = [
    "card",
    tint ? `card-tint-${tint}` : "",
    interactive ? "card-interactive" : "",
    className,
  ]
    .filter(Boolean)
    .join(" ");

  return <div className={classes} {...props} />;
}
