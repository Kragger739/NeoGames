/** Small decorative accent above the round-reveal outcome line — authored
 * SVG, not emoji, per the redesign's icon discipline. */
export function ConfettiBurst({ className }: { className?: string }) {
  return (
    <svg className={className} width="56" height="56" viewBox="0 0 56 56" fill="none" aria-hidden="true">
      <circle cx="28" cy="28" r="15" fill="#FFC93C" />
      <rect x="6" y="8" width="8" height="8" rx="2" fill="#FF5C7A" transform="rotate(20 10 12)" />
      <rect x="42" y="6" width="7" height="7" rx="2" fill="#17C3B2" transform="rotate(-15 45 9)" />
      <circle cx="49" cy="30" r="4" fill="#8B5CF6" />
      <circle cx="7" cy="34" r="4.5" fill="#FF6FB5" />
      <rect x="18" y="44" width="7" height="7" rx="2" fill="#8B5CF6" transform="rotate(30 21 47)" />
      <rect x="34" y="42" width="6" height="6" rx="2" fill="#FF5C7A" transform="rotate(-25 37 45)" />
      <path
        d="M21 29l4 5 10-10"
        stroke="#211C33"
        strokeWidth="3.5"
        strokeLinecap="round"
        strokeLinejoin="round"
        fill="none"
      />
    </svg>
  );
}

export function WhiffedIt({ className }: { className?: string }) {
  return (
    <svg className={className} width="56" height="56" viewBox="0 0 56 56" fill="none" aria-hidden="true">
      <circle cx="28" cy="28" r="15" fill="#FF4757" />
      <path
        d="M23 23l10 10M33 23l-10 10"
        stroke="#fff"
        strokeWidth="3.5"
        strokeLinecap="round"
      />
      <path
        d="M8 12c3 2 5 6 4 10"
        stroke="#8B5CF6"
        strokeWidth="3"
        strokeLinecap="round"
        fill="none"
        opacity="0.6"
      />
      <path
        d="M48 44c-3-2-5-6-4-10"
        stroke="#17C3B2"
        strokeWidth="3"
        strokeLinecap="round"
        fill="none"
        opacity="0.6"
      />
    </svg>
  );
}
