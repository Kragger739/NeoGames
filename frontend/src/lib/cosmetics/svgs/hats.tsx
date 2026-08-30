// Hat cosmetics - sit on the crown of the avatar (top ~30% of the box).

interface Props {
  className?: string;
}

export function HatParty({ className }: Props) {
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true">
      <path d="M50 3 L66 32 H34 Z" fill="#ff6fb5" />
      <path d="M50 3 L58 17 L50 20 L42 17 Z" fill="#ffc93c" />
      <path d="M42 26 L58 26" stroke="#fff9f2" strokeWidth="3" strokeLinecap="round" />
      <circle cx="50" cy="3" r="4" fill="#17c3b2" />
    </svg>
  );
}

export function HatCrown({ className }: Props) {
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true">
      <path
        d="M26 32 V13 L38 22 L50 6 L62 22 L74 13 V32 Z"
        fill="#ffc93c"
        stroke="#e6a80f"
        strokeWidth="2.5"
        strokeLinejoin="round"
      />
      <circle cx="50" cy="24" r="3.5" fill="#ff5c7a" />
      <circle cx="34" cy="27" r="3" fill="#8b5cf6" />
      <circle cx="66" cy="27" r="3" fill="#8b5cf6" />
    </svg>
  );
}
