/**
 * Hand-authored SVG illustration — no AI image generation is available in
 * this environment (no API key, no harness-native tool), so illustration
 * work across the redesign is original vector art instead of generated
 * raster images. This is the dashboard hero: a grinning eighth-note
 * "mascot" mid-celebration, confetti bursting around it.
 */
export function PartyNote({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      width="220"
      height="220"
      viewBox="0 0 220 220"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      role="img"
      aria-label="A grinning music note mascot celebrating with confetti"
    >
      {/* confetti - each piece twinkles on its own offbeat, see app.css */}
      <rect className="party-note-confetti-piece" style={{ animationDelay: "0s" }} x="18" y="34" width="10" height="10" rx="2" fill="#FFC93C" transform="rotate(18 23 39)" />
      <rect className="party-note-confetti-piece" style={{ animationDelay: "0.3s" }} x="182" y="24" width="9" height="9" rx="2" fill="#17C3B2" transform="rotate(-14 186 28)" />
      <circle className="party-note-confetti-piece" style={{ animationDelay: "0.6s" }} cx="30" cy="150" r="6" fill="#8B5CF6" />
      <circle className="party-note-confetti-piece" style={{ animationDelay: "0.15s" }} cx="196" cy="150" r="7" fill="#FF6FB5" />
      <rect className="party-note-confetti-piece" style={{ animationDelay: "0.45s" }} x="24" y="96" width="8" height="8" rx="2" fill="#FF5C7A" transform="rotate(30 28 100)" />
      <rect className="party-note-confetti-piece" style={{ animationDelay: "0.75s" }} x="188" y="100" width="8" height="8" rx="2" fill="#FFC93C" transform="rotate(-20 192 104)" />
      <circle className="party-note-confetti-piece" style={{ animationDelay: "0.9s" }} cx="60" cy="20" r="5" fill="#17C3B2" />
      <circle className="party-note-confetti-piece" style={{ animationDelay: "0.2s" }} cx="160" cy="196" r="5" fill="#FF5C7A" />

      {/* note stem + flag - a small independent sway, like keeping time */}
      <g className="party-note-stem">
        <path d="M118 40c0-3.3 2.7-6 6-6h6c3.3 0 6 2.7 6 6v88h-18V40z" fill="#8B5CF6" />
        <path
          d="M136 40c14 4 28 16 28 34-6-6-16-10-28-10V40z"
          fill="#FF6FB5"
        />
      </g>

      {/* note head (the "face") - grooves side to side on the beat */}
      <g className="party-note-head">
        <ellipse cx="100" cy="150" rx="42" ry="36" fill="#FF5C7A" />
        <ellipse cx="88" cy="138" rx="14" ry="10" fill="#FF87A0" opacity="0.6" />

        {/* face */}
        <circle cx="84" cy="146" r="7" fill="#211C33" />
        <circle cx="116" cy="146" r="7" fill="#211C33" />
        <circle cx="86.5" cy="143.5" r="2.2" fill="#fff" />
        <circle cx="118.5" cy="143.5" r="2.2" fill="#fff" />
        <path
          d="M80 164c6 10 34 10 40 0"
          stroke="#211C33"
          strokeWidth="5"
          strokeLinecap="round"
          fill="none"
        />

        {/* cheeks */}
        <circle cx="72" cy="158" r="6" fill="#FFC93C" opacity="0.55" />
        <circle cx="128" cy="158" r="6" fill="#FFC93C" opacity="0.55" />
      </g>

      {/* stubby arms, mid-cheer - swing opposite each other like dancing */}
      <g className="party-note-arm-left">
        <path d="M62 148c-10-6-18-2-22 6" stroke="#FF5C7A" strokeWidth="12" strokeLinecap="round" />
        <circle cx="36" cy="158" r="8" fill="#FFC93C" />
      </g>
      <g className="party-note-arm-right">
        <path d="M138 148c10-6 18-2 22 6" stroke="#FF5C7A" strokeWidth="12" strokeLinecap="round" />
        <circle cx="164" cy="158" r="8" fill="#FFC93C" />
      </g>
    </svg>
  );
}
