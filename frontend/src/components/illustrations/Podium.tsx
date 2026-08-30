/** Winner's podium — hand-authored SVG for the Results page hero. */
export function Podium({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      width="200"
      height="140"
      viewBox="0 0 200 140"
      fill="none"
      role="img"
      aria-label="A three-place winners' podium with confetti"
    >
      <rect x="8" y="76" width="52" height="54" rx="10" fill="#8B5CF6" />
      <rect x="66" y="40" width="68" height="90" rx="10" fill="#FF5C7A" />
      <rect x="140" y="92" width="52" height="38" rx="10" fill="#17C3B2" />

      <text x="34" y="112" textAnchor="middle" fontFamily="'Space Grotesk', sans-serif" fontWeight="800" fontSize="26" fill="#fff">2</text>
      <text x="100" y="92" textAnchor="middle" fontFamily="'Space Grotesk', sans-serif" fontWeight="800" fontSize="32" fill="#fff">1</text>
      <text x="166" y="120" textAnchor="middle" fontFamily="'Space Grotesk', sans-serif" fontWeight="800" fontSize="22" fill="#fff">3</text>

      <circle cx="100" cy="18" r="16" fill="#FFC93C" />
      <path d="M92 18l5 6 11-12" stroke="#211C33" strokeWidth="3.5" strokeLinecap="round" strokeLinejoin="round" fill="none" />

      <rect x="14" y="10" width="7" height="7" rx="2" fill="#FF6FB5" transform="rotate(20 17 13)" />
      <rect x="176" y="24" width="6" height="6" rx="2" fill="#FFC93C" transform="rotate(-15 179 27)" />
      <circle cx="184" cy="10" r="4" fill="#8B5CF6" />
      <circle cx="10" cy="46" r="3.5" fill="#17C3B2" />
    </svg>
  );
}
