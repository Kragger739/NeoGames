interface DdfGmAnswerKeyProps {
  correctAnswer: string | null;
  /** Players' submitted text this question (typed mode). Empty in couch mode. */
  answers: Array<{ roomPlayerId: number; nickname: string; answerText: string | null }>;
  turnNickname: string | null;
  visible: boolean;
}

/**
 * GM-only: the current question's answer key plus whatever players typed, so
 * the Game Master can grade ✅/❌ without waiting for the public reveal. Only
 * rendered while a question is live (state question / answer_submitted).
 */
export function DdfGmAnswerKey({ correctAnswer, answers, turnNickname, visible }: DdfGmAnswerKeyProps) {
  if (!visible) return null;

  return (
    <div className="ddf-gm-answer-key">
      <p className="ddf-gm-answer-key-correct">
        Answer{turnNickname ? ` (${turnNickname}'s turn)` : ""}: {correctAnswer ?? "…"}
      </p>
      {answers.length > 0 && (
        <ul>
          {answers.map((a) => (
            <li key={a.roomPlayerId}>
              <strong>{a.nickname}:</strong> {a.answerText ?? <em>no answer</em>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
