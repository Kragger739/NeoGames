interface DdfEliminationOverlayProps {
  nickname: string;
}

export function DdfEliminationOverlay({ nickname }: DdfEliminationOverlayProps) {
  return (
    <div className="ddf-elimination-overlay">
      <div className="ddf-elimination-card">
        <span className="ddf-elimination-badge">🔴 ELIMINATED</span>
        <span className="ddf-elimination-name">{nickname}</span>
      </div>
    </div>
  );
}
