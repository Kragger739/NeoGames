// Background cosmetics - fill the whole box behind the photo / silhouette.
// Visible in the rounded corners around a photo, and fully behind the
// placeholder silhouette.

interface Props {
  className?: string;
}

export function BgWash({ className }: Props) {
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true" preserveAspectRatio="none">
      <defs>
        <radialGradient id="bg-wash-g" cx="50%" cy="35%" r="75%">
          <stop offset="0%" stopColor="#fff2cc" />
          <stop offset="100%" stopColor="#ffe4ea" />
        </radialGradient>
      </defs>
      <rect width="100" height="100" fill="url(#bg-wash-g)" />
    </svg>
  );
}

export function BgConfetti({ className }: Props) {
  const bits = [
    { x: 14, y: 20, r: 18, c: "#ff5c7a" },
    { x: 82, y: 16, r: -22, c: "#17c3b2" },
    { x: 24, y: 70, r: 40, c: "#ffc93c" },
    { x: 74, y: 78, r: 12, c: "#8b5cf6" },
    { x: 50, y: 12, r: -10, c: "#ff6fb5" },
    { x: 88, y: 52, r: 32, c: "#ffc93c" },
    { x: 10, y: 46, r: -35, c: "#8b5cf6" },
    { x: 60, y: 88, r: 20, c: "#17c3b2" },
  ];
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true" preserveAspectRatio="none">
      <rect width="100" height="100" fill="#fff9f2" />
      {bits.map((b, i) => (
        <rect
          key={i}
          x={b.x}
          y={b.y}
          width="7"
          height="7"
          rx="2"
          fill={b.c}
          transform={`rotate(${b.r} ${b.x + 3.5} ${b.y + 3.5})`}
        />
      ))}
    </svg>
  );
}

export function BgSunburst({ className }: Props) {
  const rays = Array.from({ length: 12 }, (_, i) => i * 30);
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true" preserveAspectRatio="none">
      <rect width="100" height="100" fill="#fff2cc" />
      {rays.map((deg, i) => (
        <path
          key={i}
          d="M50 50 L44 -20 L56 -20 Z"
          fill={i % 2 === 0 ? "#ffe4ea" : "#fff2cc"}
          transform={`rotate(${deg} 50 50)`}
        />
      ))}
      <circle cx="50" cy="50" r="30" fill="#fff9f2" />
    </svg>
  );
}
