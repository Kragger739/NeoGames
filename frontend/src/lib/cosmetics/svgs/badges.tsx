// Badge cosmetics - a small corner flair, pulled in to the lower-right of the
// circular avatar so the round backdrop sits fully inside the edge.

interface Props {
  className?: string;
}

function Backdrop() {
  return <circle cx="72" cy="72" r="13" fill="#fff9f2" stroke="#f0dfc8" strokeWidth="1.5" />;
}

export function BadgeDot({ className }: Props) {
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true">
      <Backdrop />
      <circle cx="72" cy="72" r="8" fill="#17c3b2" />
    </svg>
  );
}

export function BadgeStar({ className }: Props) {
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true">
      <Backdrop />
      <path
        d="M72 63 l2.6 5.3 5.9 0.9 -4.3 4.1 1 5.8 -5.2 -2.8 -5.2 2.8 1 -5.8 -4.3 -4.1 5.9 -0.9 Z"
        fill="#ffc93c"
        stroke="#e6a80f"
        strokeWidth="1.4"
        strokeLinejoin="round"
      />
    </svg>
  );
}

export function BadgeBolt({ className }: Props) {
  return (
    <svg className={className} viewBox="0 0 100 100" fill="none" aria-hidden="true">
      <Backdrop />
      <path
        d="M74 62 L65 73 H71 L68 82 L79 70 H73 Z"
        fill="#8b5cf6"
        stroke="#6d3ce0"
        strokeWidth="1.4"
        strokeLinejoin="round"
      />
    </svg>
  );
}
