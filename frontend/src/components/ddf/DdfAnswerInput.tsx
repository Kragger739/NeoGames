import { type FormEvent, useState } from "react";

import { Button } from "../ui/Button";

interface DdfAnswerInputProps {
  onSubmit: (answerText: string) => Promise<void>;
  disabled: boolean;
  submitted: boolean;
}

export function DdfAnswerInput({ onSubmit, disabled, submitted }: DdfAnswerInputProps) {
  const [answer, setAnswer] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: FormEvent) {
    e.preventDefault();
    if (!answer.trim()) return;
    setSubmitting(true);
    setError(null);
    try {
      await onSubmit(answer.trim());
    } catch {
      setError("Couldn't submit your answer - try again.");
    } finally {
      setSubmitting(false);
    }
  }

  if (submitted) {
    return <p className="ddf-answer-submitted">Answer submitted — waiting for the others…</p>;
  }

  return (
    <form className="ddf-answer-form" onSubmit={handleSubmit}>
      <input
        type="text"
        value={answer}
        onChange={(e) => setAnswer(e.target.value)}
        placeholder="Type your answer…"
        disabled={disabled || submitting}
        maxLength={200}
        autoFocus
      />
      <Button type="submit" variant="turquoise" disabled={disabled || submitting || !answer.trim()}>
        {submitting ? "Sending…" : "Submit"}
      </Button>
      {error && <p className="form-error">{error}</p>}
    </form>
  );
}
