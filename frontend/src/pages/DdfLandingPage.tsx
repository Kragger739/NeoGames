import { type FormEvent, useEffect, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { ArrowLeft } from "lucide-react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import type { DdfLanguage } from "../lib/ddfTypes";
import type { DatasetsIndex, DatasetSummary } from "../lib/workshopTypes";
import { Button } from "../components/ui/Button";
import { Card } from "../components/ui/Card";
import { IconButton } from "../components/ui/IconButton";

interface CreateDdfRoomResponse {
  code: string;
}

export function DdfLandingPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const initialDataset = searchParams.get("dataset");
  const [roundsPerVoting, setRoundsPerVoting] = useState(2);
  const [questionTimerSeconds, setQuestionTimerSeconds] = useState(30);
  const [votingTimerSeconds, setVotingTimerSeconds] = useState(30);
  const [language, setLanguage] = useState<DdfLanguage>("en");
  const [couchMode, setCouchMode] = useState(true);
  const [safeMode, setSafeMode] = useState(false);
  const [datasetId, setDatasetId] = useState<number | null>(
    initialDataset && /^\d+$/.test(initialDataset) ? Number(initialDataset) : null,
  );
  const [myDatasets, setMyDatasets] = useState<DatasetSummary[]>([]);
  const [communityDatasets, setCommunityDatasets] = useState<DatasetSummary[]>([]);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Question sets the host can pick from: their own DDF datasets plus any
  // public ones. Selecting one overrides the built-in question pool and
  // carries its own language.
  useEffect(() => {
    let cancelled = false;
    api
      .get<DatasetsIndex>("/api/datasets", { params: { type: "ddf" } })
      .then((response) => {
        if (cancelled) return;
        setMyDatasets(response.data.mine);
        setCommunityDatasets(response.data.community);
      })
      .catch(() => {
        if (!cancelled) {
          setMyDatasets([]);
          setCommunityDatasets([]);
        }
      });
    return () => {
      cancelled = true;
    };
  }, []);

  async function handleCreate(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setCreating(true);
    try {
      const response = await api.post<CreateDdfRoomResponse>("/api/ddf-rooms", {
        rounds_per_voting: roundsPerVoting,
        question_timer_seconds: questionTimerSeconds,
        voting_timer_seconds: votingTimerSeconds,
        language,
        couch_mode: couchMode,
        safe_mode: safeMode,
        ...(datasetId !== null ? { dataset_id: datasetId } : {}),
      });
      navigate(`/ddf-rooms/${response.data.code}/lobby`);
    } catch (err) {
      setError(firstValidationError(err));
      setCreating(false);
    }
  }

  return (
    <div className="ddf-landing-page">
      <div className="ddf-landing-header">
        <IconButton icon={ArrowLeft} label="Back to Home" variant="ghost" onClick={() => navigate("/")} />
      </div>
      <h1>DER DÜMMSTE FLIEGT</h1>
      <p className="hint">Set up a new game — you'll moderate as Game Master.</p>
      <Card className="ddf-landing-card">
        <form onSubmit={handleCreate}>
          <label>
            Questions before voting
            <input
              type="number"
              min={1}
              max={10}
              value={roundsPerVoting}
              onChange={(e) => setRoundsPerVoting(Number(e.target.value))}
            />
          </label>
          <label>
            Question timer (seconds)
            <input
              type="number"
              min={5}
              max={120}
              value={questionTimerSeconds}
              onChange={(e) => setQuestionTimerSeconds(Number(e.target.value))}
            />
          </label>
          <label>
            Voting timer (seconds)
            <input
              type="number"
              min={5}
              max={120}
              value={votingTimerSeconds}
              onChange={(e) => setVotingTimerSeconds(Number(e.target.value))}
            />
          </label>
          <label>
            Question language
            <select
              value={language}
              disabled={datasetId !== null}
              onChange={(e) => setLanguage(e.target.value as DdfLanguage)}
            >
              <option value="en">English</option>
              <option value="de">German</option>
            </select>
          </label>
          <label>
            Question source
            <select
              value={datasetId ?? ""}
              onChange={(e) => setDatasetId(e.target.value === "" ? null : Number(e.target.value))}
            >
              <option value="">Built-in questions</option>
              {myDatasets.length > 0 && (
                <optgroup label="My datasets">
                  {myDatasets.map((dataset) => (
                    <option key={dataset.id} value={dataset.id} disabled={dataset.item_count === 0}>
                      {dataset.name}
                      {dataset.item_count === 0
                        ? " (empty — add questions first)"
                        : ` (${dataset.item_count} questions)`}
                    </option>
                  ))}
                </optgroup>
              )}
              {communityDatasets.length > 0 && (
                <optgroup label="Community">
                  {communityDatasets.map((dataset) => (
                    <option key={dataset.id} value={dataset.id} disabled={dataset.item_count === 0}>
                      {dataset.name}
                      {dataset.owner_username ? ` — ${dataset.owner_username}` : ""}
                      {dataset.item_count === 0 ? " (empty)" : ` (${dataset.item_count} questions)`}
                    </option>
                  ))}
                </optgroup>
              )}
            </select>
          </label>
          {datasetId !== null && (
            <p className="hint">
              This question set carries its own language — the language picker above is ignored.
            </p>
          )}
          <label className="ddf-checkbox-label">
            <input type="checkbox" checked={couchMode} onChange={(e) => setCouchMode(e.target.checked)} />
            Couch Mode — everyone answers out loud, no typed answers
          </label>
          <label className="ddf-checkbox-label">
            <input type="checkbox" checked={safeMode} onChange={(e) => setSafeMode(e.target.checked)} />
            Safe mode — a player who aced every question this cycle can't be voted out
          </label>
          {error && <p className="form-error">{error}</p>}
          <Button type="submit" variant="grape" size="lg" disabled={creating}>
            {creating ? "Creating…" : "Create game"}
          </Button>
        </form>
      </Card>
    </div>
  );
}
