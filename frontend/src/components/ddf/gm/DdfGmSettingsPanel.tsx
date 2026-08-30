import { useState } from "react";

import type { DdfLanguage } from "../../../lib/ddfTypes";
import { Button } from "../../ui/Button";

interface DdfGmSettingsPanelProps {
  roundsPerVoting: number;
  questionTimerSeconds: number;
  votingTimerSeconds: number;
  language: DdfLanguage;
  couchMode: boolean;
  safeMode: boolean;
  onSave: (settings: {
    rounds_per_voting: number;
    question_timer_seconds: number;
    voting_timer_seconds: number;
    language: DdfLanguage;
    couch_mode: boolean;
    safe_mode: boolean;
  }) => Promise<void>;
  disabled: boolean;
}

export function DdfGmSettingsPanel({
  roundsPerVoting,
  questionTimerSeconds,
  votingTimerSeconds,
  language,
  couchMode,
  safeMode,
  onSave,
  disabled,
}: DdfGmSettingsPanelProps) {
  const [rounds, setRounds] = useState(roundsPerVoting);
  const [questionTimer, setQuestionTimer] = useState(questionTimerSeconds);
  const [votingTimer, setVotingTimer] = useState(votingTimerSeconds);
  const [lang, setLang] = useState<DdfLanguage>(language);
  const [couch, setCouch] = useState(couchMode);
  const [safe, setSafe] = useState(safeMode);
  const [saving, setSaving] = useState(false);

  async function handleSave() {
    setSaving(true);
    try {
      await onSave({
        rounds_per_voting: rounds,
        question_timer_seconds: questionTimer,
        voting_timer_seconds: votingTimer,
        language: lang,
        couch_mode: couch,
        safe_mode: safe,
      });
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="ddf-gm-settings">
      <label>
        Questions before voting
        <input
          type="number"
          min={1}
          max={10}
          value={rounds}
          disabled={disabled}
          onChange={(e) => setRounds(Number(e.target.value))}
        />
      </label>
      <label>
        Question timer (s)
        <input
          type="number"
          min={5}
          max={120}
          value={questionTimer}
          disabled={disabled}
          onChange={(e) => setQuestionTimer(Number(e.target.value))}
        />
      </label>
      <label>
        Voting timer (s)
        <input
          type="number"
          min={5}
          max={120}
          value={votingTimer}
          disabled={disabled}
          onChange={(e) => setVotingTimer(Number(e.target.value))}
        />
      </label>
      <label>
        Question language
        <select value={lang} disabled={disabled} onChange={(e) => setLang(e.target.value as DdfLanguage)}>
          <option value="en">English</option>
          <option value="de">German</option>
        </select>
      </label>
      <label className="ddf-checkbox-label">
        <input type="checkbox" checked={couch} disabled={disabled} onChange={(e) => setCouch(e.target.checked)} />
        Couch Mode — everyone answers out loud, no typed answers
      </label>
      <label className="ddf-checkbox-label">
        <input type="checkbox" checked={safe} disabled={disabled} onChange={(e) => setSafe(e.target.checked)} />
        Safe mode — a player who aced every question this cycle can't be voted out
      </label>
      <Button variant="ghost" disabled={disabled || saving} onClick={() => void handleSave()}>
        {saving ? "Saving…" : "Save settings"}
      </Button>
    </div>
  );
}
