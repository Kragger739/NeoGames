import { Heart } from "lucide-react";

interface DdfHeartsRowProps {
  hearts: number;
  maxHearts?: number;
  size?: number;
}

/** ❤️❤️🖤 - filled hearts for what's left, hollow for what's lost. */
export function DdfHeartsRow({ hearts, maxHearts = 3, size = 18 }: DdfHeartsRowProps) {
  return (
    <span className="ddf-hearts-row" aria-label={`${hearts} of ${maxHearts} hearts remaining`}>
      {Array.from({ length: maxHearts }, (_, i) => (
        <Heart
          key={i}
          size={size}
          className={i < hearts ? "ddf-heart ddf-heart-full" : "ddf-heart ddf-heart-empty"}
          fill={i < hearts ? "currentColor" : "none"}
          strokeWidth={2}
        />
      ))}
    </span>
  );
}
