interface DdfLifeLostAnimationProps {
  nickname: string;
  heartsRemaining: number;
}

/** One-shot dramatic overlay when someone loses a heart - a single flash/shake, not a repeating strobe. */
export function DdfLifeLostAnimation({ nickname, heartsRemaining }: DdfLifeLostAnimationProps) {
  return (
    <div className="ddf-life-lost-overlay">
      <div className="ddf-life-lost-card">
        <span className="ddf-life-lost-name">{nickname}</span>
        <span className="ddf-life-lost-hearts" aria-hidden="true">
          {"❤️".repeat(heartsRemaining)}
          {"🖤".repeat(3 - heartsRemaining)}
        </span>
      </div>
    </div>
  );
}
