// Effect cosmetics - the only animated layer. Reserved for the top season
// tier. The rotation lives in a CSS class (.cosmetic-spin in avatar.css) so
// prefers-reduced-motion can switch it off; <Avatar animated={false}> also
// skips rendering this layer entirely on list surfaces.

interface Props {
  className?: string;
}

export function EffectSparkle({ className }: Props) {
  const sparkles = Array.from({ length: 6 }, (_, i) => {
    const a = (i / 6) * Math.PI * 2;
    return { x: 50 + Math.cos(a) * 43, y: 50 + Math.sin(a) * 43 };
  });
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true">
      <g className="cosmetic-spin" style={{ transformOrigin: "50px 50px" }}>
        {sparkles.map((s, i) => (
          <path
            key={i}
            d={`M${s.x} ${s.y - 5} L${s.x + 1.6} ${s.y - 1.6} L${s.x + 5} ${s.y} L${s.x + 1.6} ${s.y + 1.6} L${s.x} ${s.y + 5} L${s.x - 1.6} ${s.y + 1.6} L${s.x - 5} ${s.y} L${s.x - 1.6} ${s.y - 1.6} Z`}
            fill={i % 2 === 0 ? "#ffc93c" : "#ff6fb5"}
          />
        ))}
      </g>
    </svg>
  );
}
