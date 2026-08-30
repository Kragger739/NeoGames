// Accessory cosmetics - worn low on the avatar (necklaces / chains).

interface Props {
  className?: string;
}

export function AccessoryChain({ className }: Props) {
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true">
      <path
        d="M20 60 Q50 92 80 60"
        stroke="#ffc93c"
        strokeWidth="5"
        strokeLinecap="round"
        fill="none"
      />
      <path
        d="M20 60 Q50 92 80 60"
        stroke="#e6a80f"
        strokeWidth="1.5"
        strokeLinecap="round"
        strokeDasharray="1 6"
        fill="none"
      />
      <circle cx="50" cy="84" r="7" fill="#ffc93c" stroke="#e6a80f" strokeWidth="2" />
      <circle cx="50" cy="84" r="2.5" fill="#fff9f2" />
    </svg>
  );
}
