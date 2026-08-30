import { Button } from "../ui/Button";

interface DdfGameOverScreenProps {
  winnerNickname: string | null;
  onBackToLobby?: () => void;
}

export function DdfGameOverScreen({ winnerNickname, onBackToLobby }: DdfGameOverScreenProps) {
  return (
    <div className="ddf-game-over">
      <h1 className="ddf-game-over-title">GAME OVER</h1>
      {winnerNickname ? (
        <p className="ddf-game-over-winner">🏆 {winnerNickname} WINS!</p>
      ) : (
        <p className="ddf-game-over-winner">The game ended early — no winner.</p>
      )}
      {onBackToLobby && (
        <Button variant="turquoise" size="lg" onClick={onBackToLobby}>
          Back to lobby
        </Button>
      )}
    </div>
  );
}
