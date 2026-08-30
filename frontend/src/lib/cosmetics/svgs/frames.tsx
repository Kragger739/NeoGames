// Frame cosmetics - rings drawn around the (circular) avatar's edge.
// Everything is kept just inside r=48 so the box's overflow clip never bites.

interface Props {
  className?: string;
}

export function FrameSoft({ className }: Props) {
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true">
      <circle cx="50" cy="50" r="46" stroke="#17c3b2" strokeWidth="5" />
      <circle cx="50" cy="50" r="42" stroke="#d9f7f2" strokeWidth="2" />
    </svg>
  );
}

export function FrameDashed({ className }: Props) {
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true">
      <circle
        cx="50"
        cy="50"
        r="46"
        stroke="#ff5c7a"
        strokeWidth="5"
        strokeLinecap="round"
        strokeDasharray="3 8"
      />
    </svg>
  );
}

export function FrameScallop({ className }: Props) {
  const dots = Array.from({ length: 18 }, (_, i) => {
    const a = (i / 18) * Math.PI * 2;
    return { x: 50 + Math.cos(a) * 45, y: 50 + Math.sin(a) * 45 };
  });
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true">
      <circle cx="50" cy="50" r="44" stroke="#8b5cf6" strokeWidth="4" />
      {dots.map((d, i) => (
        <circle key={i} cx={d.x} cy={d.y} r="3.2" fill="#ffc93c" stroke="#e6a80f" strokeWidth="1" />
      ))}
    </svg>
  );
}
