import { type FormEvent, useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { ArrowDown, ArrowUp, Pencil, Trash2 } from "lucide-react";

import { firstValidationError } from "../lib/errors";
import {
  DDF_CATEGORIES,
  type DatasetQuestion,
  type DdfCategory,
  categoryLabel,
} from "../lib/workshopTypes";
import { useWorkshopStore } from "../stores/workshopStore";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

export function DatasetEditorPage() {
  const { id } = useParams<{ id: string }>();
  const datasetId = Number(id);
  const navigate = useNavigate();

  const status = useWorkshopStore((state) => state.currentStatus);
  const dataset = useWorkshopStore((state) => state.current);
  const fetchOne = useWorkshopStore((state) => state.fetchOne);
  const update = useWorkshopStore((state) => state.update);
  const remove = useWorkshopStore((state) => state.remove);
  const saving = useWorkshopStore((state) => state.saving);

  const [name, setName] = useState("");
  const [editingName, setEditingName] = useState(false);
  const [headerError, setHeaderError] = useState<string | null>(null);
  const [preview, setPreview] = useState(false);
  const [showAnswers, setShowAnswers] = useState(false);

  useEffect(() => {
    if (Number.isFinite(datasetId)) void fetchOne(datasetId);
  }, [datasetId, fetchOne]);

  useEffect(() => {
    if (dataset) setName(dataset.name);
  }, [dataset]);

  if (status !== "ready" || !dataset) {
    return (
      <div className="dataset-editor">
        <p className="hint">Loading…</p>
      </div>
    );
  }

  const isDdf = dataset.type === "ddf";

  async function saveName(e: FormEvent) {
    e.preventDefault();
    setHeaderError(null);
    try {
      await update(datasetId, { name: name.trim() });
      setEditingName(false);
    } catch (err) {
      setHeaderError(firstValidationError(err));
    }
  }

  async function toggleVisibility() {
    await update(datasetId, {
      visibility: dataset!.visibility === "public" ? "private" : "public",
    });
  }

  async function handleDelete() {
    if (!confirm(`Delete "${dataset!.name}"? This can’t be undone.`)) return;
    await remove(datasetId);
    navigate("/workshop");
  }

  function useInGame() {
    navigate(isDdf ? `/ddf?dataset=${datasetId}` : "/songle");
  }

  return (
    <div className="dataset-editor">
      <p>
        <Link to="/workshop">← Workshop</Link>
      </p>

      <div className="dataset-header">
        {editingName ? (
          <form className="dataset-name-form" onSubmit={saveName}>
            <input value={name} onChange={(e) => setName(e.target.value)} maxLength={80} required autoFocus />
            <Button type="submit" disabled={saving}>
              Save
            </Button>
            <Button
              variant="ghost"
              type="button"
              onClick={() => {
                setName(dataset.name);
                setEditingName(false);
                setHeaderError(null);
              }}
            >
              Cancel
            </Button>
          </form>
        ) : (
          <h1>
            {dataset.name}
            <Button variant="ghost" className="dataset-rename" onClick={() => setEditingName(true)}>
              <Pencil size={15} strokeWidth={2.25} />
            </Button>
          </h1>
        )}
        {headerError && <p className="form-error">{headerError}</p>}

        <div className="dataset-header-actions">
          <Badge tone={isDdf ? "grape" : "turquoise"}>{isDdf ? "Questions" : "Songle"}</Badge>
          <button type="button" className="dataset-visibility" onClick={() => void toggleVisibility()}>
            {dataset.visibility === "public" ? "Public" : "Private"}
          </button>
          {isDdf && (
            <Button variant="ghost" onClick={() => setPreview(true)}>
              Preview
            </Button>
          )}
          <Button variant="turquoise" onClick={useInGame}>
            Use in a game
          </Button>
          <Button variant="danger" onClick={() => void handleDelete()} aria-label="Delete dataset">
            <Trash2 size={16} strokeWidth={2.25} />
          </Button>
        </div>
      </div>

      {isDdf ? <QuestionEditor datasetId={datasetId} questions={dataset.questions ?? []} /> : (
        <TrackEditor datasetId={datasetId} />
      )}

      {preview && (
        <div className="wk-preview" role="dialog" aria-label="Dataset preview">
          <div className="wk-preview-card">
            <div className="wk-preview-head">
              <h2>{dataset.name}</h2>
              <label className="wk-preview-toggle">
                <input type="checkbox" checked={showAnswers} onChange={(e) => setShowAnswers(e.target.checked)} />
                Show answers
              </label>
              <Button variant="ghost" onClick={() => setPreview(false)}>
                Close
              </Button>
            </div>
            {(dataset.questions ?? []).length === 0 ? (
              <p className="hint">No questions yet.</p>
            ) : (
              <ol className="wk-preview-list">
                {(dataset.questions ?? []).map((q, i) => (
                  <li key={q.id}>
                    <span>
                      {i + 1}. {q.text}
                    </span>
                    {showAnswers && <span className="wk-preview-answer">→ {q.correct_answer}</span>}
                  </li>
                ))}
              </ol>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

// --- DDF question editor ---------------------------------------------------

function QuestionEditor({ datasetId, questions }: { datasetId: number; questions: DatasetQuestion[] }) {
  const addQuestion = useWorkshopStore((state) => state.addQuestion);
  const updateQuestion = useWorkshopStore((state) => state.updateQuestion);
  const deleteQuestion = useWorkshopStore((state) => state.deleteQuestion);
  const reorderQuestions = useWorkshopStore((state) => state.reorderQuestions);
  const saving = useWorkshopStore((state) => state.saving);

  const [text, setText] = useState("");
  const [answer, setAnswer] = useState("");
  const [category, setCategory] = useState<DdfCategory>("everyday_knowledge");
  const [error, setError] = useState<string | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null);

  async function handleAdd(e: FormEvent) {
    e.preventDefault();
    setError(null);
    try {
      await addQuestion(datasetId, { text: text.trim(), correct_answer: answer.trim(), category });
      setText("");
      setAnswer("");
    } catch (err) {
      setError(firstValidationError(err));
    }
  }

  function move(index: number, delta: number) {
    const next = [...questions];
    const target = index + delta;
    if (target < 0 || target >= next.length) return;
    [next[index], next[target]] = [next[target], next[index]];
    void reorderQuestions(
      datasetId,
      next.map((q) => q.id),
    );
  }

  return (
    <>
      <form className="wk-add-question" onSubmit={handleAdd}>
        <label>
          Question
          <textarea value={text} onChange={(e) => setText(e.target.value)} maxLength={500} rows={2} required />
        </label>
        <div className="wk-add-row">
          <label>
            Answer
            <input value={answer} onChange={(e) => setAnswer(e.target.value)} maxLength={200} required />
          </label>
          <label>
            Category
            <select value={category} onChange={(e) => setCategory(e.target.value as DdfCategory)}>
              {DDF_CATEGORIES.map((c) => (
                <option key={c} value={c}>
                  {categoryLabel(c)}
                </option>
              ))}
            </select>
          </label>
        </div>
        {error && <p className="form-error">{error}</p>}
        <Button type="submit" disabled={saving}>
          {saving ? "Adding…" : "Add question"}
        </Button>
      </form>

      <h2>Questions ({questions.length})</h2>
      {questions.length === 0 ? (
        <p className="hint">No questions yet — add one above. You’ll need at least one to use this set in a game.</p>
      ) : (
        <ul className="wk-question-list">
          {questions.map((q, i) => (
            <li key={q.id}>
              {editingId === q.id ? (
                <QuestionRowEditor
                  question={q}
                  onCancel={() => setEditingId(null)}
                  onSave={async (payload) => {
                    await updateQuestion(datasetId, q.id, payload);
                    setEditingId(null);
                  }}
                />
              ) : (
                <>
                  <span className="wk-q-reorder">
                    <button type="button" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)}>
                      <ArrowUp size={14} strokeWidth={2.5} />
                    </button>
                    <button
                      type="button"
                      aria-label="Move down"
                      disabled={i === questions.length - 1}
                      onClick={() => move(i, 1)}
                    >
                      <ArrowDown size={14} strokeWidth={2.5} />
                    </button>
                  </span>
                  <span className="wk-q-body">
                    <span className="wk-q-text">{q.text}</span>
                    <span className="wk-q-meta">
                      <Badge tone="sunflower">{categoryLabel(q.category)}</Badge>
                      <span className="hint">→ {q.correct_answer}</span>
                    </span>
                  </span>
                  <span className="wk-actions">
                    <Button variant="ghost" onClick={() => setEditingId(q.id)} aria-label="Edit">
                      <Pencil size={15} strokeWidth={2.25} />
                    </Button>
                    <Button
                      variant="danger"
                      onClick={() => void deleteQuestion(datasetId, q.id)}
                      aria-label="Delete"
                    >
                      <Trash2 size={15} strokeWidth={2.25} />
                    </Button>
                  </span>
                </>
              )}
            </li>
          ))}
        </ul>
      )}
    </>
  );
}

function QuestionRowEditor({
  question,
  onSave,
  onCancel,
}: {
  question: DatasetQuestion;
  onSave: (payload: { text: string; correct_answer: string; category: string }) => Promise<void>;
  onCancel: () => void;
}) {
  const [text, setText] = useState(question.text);
  const [answer, setAnswer] = useState(question.correct_answer);
  const [category, setCategory] = useState<DdfCategory>(question.category);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      await onSave({ text: text.trim(), correct_answer: answer.trim(), category });
    } catch (err) {
      setError(firstValidationError(err));
      setBusy(false);
    }
  }

  return (
    <form className="wk-q-edit" onSubmit={submit}>
      <textarea value={text} onChange={(e) => setText(e.target.value)} maxLength={500} rows={2} required />
      <div className="wk-add-row">
        <input value={answer} onChange={(e) => setAnswer(e.target.value)} maxLength={200} required />
        <select value={category} onChange={(e) => setCategory(e.target.value as DdfCategory)}>
          {DDF_CATEGORIES.map((c) => (
            <option key={c} value={c}>
              {categoryLabel(c)}
            </option>
          ))}
        </select>
      </div>
      {error && <p className="form-error">{error}</p>}
      <div className="wk-create-actions">
        <Button type="submit" disabled={busy}>
          Save
        </Button>
        <Button variant="ghost" type="button" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

// --- Songle track editor -------------------------------------------------

function TrackEditor({ datasetId }: { datasetId: number }) {
  const dataset = useWorkshopStore((state) => state.current);
  const importPlaylist = useWorkshopStore((state) => state.importPlaylist);
  const removeTrack = useWorkshopStore((state) => state.removeTrack);
  const saving = useWorkshopStore((state) => state.saving);

  const [playlist, setPlaylist] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const tracks = dataset?.tracks ?? [];

  async function handleImport(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setNotice(null);
    if (tracks.length > 0 && !confirm("Importing replaces the current track list. Continue?")) return;
    try {
      await importPlaylist(datasetId, playlist.trim());
      setPlaylist("");
      setNotice("Playlist imported.");
    } catch (err) {
      setError(firstValidationError(err));
    }
  }

  return (
    <>
      <form className="wk-import" onSubmit={handleImport}>
        <label>
          Deezer playlist link or id
          <input
            value={playlist}
            onChange={(e) => setPlaylist(e.target.value)}
            placeholder="https://www.deezer.com/playlist/1234567"
            required
          />
        </label>
        {error && <p className="form-error">{error}</p>}
        {notice && <p className="hint">{notice}</p>}
        <Button type="submit" disabled={saving}>
          {saving ? "Importing…" : tracks.length > 0 ? "Replace playlist" : "Import"}
        </Button>
      </form>

      <h2>Tracks ({tracks.length})</h2>
      {tracks.length === 0 ? (
        <p className="hint">No tracks yet — import a Deezer playlist above.</p>
      ) : (
        <ul className="wk-track-list">
          {tracks.map((t) => (
            <li key={t.id}>
              {t.album_art_url ? (
                <img className="wk-track-art" src={t.album_art_url} alt="" width={36} height={36} />
              ) : (
                <span className="wk-track-art art-placeholder" aria-hidden="true" />
              )}
              <span className="wk-track-body">
                <span className="wk-track-title">{t.title}</span>
                <span className="hint">{t.artist}</span>
              </span>
              <Button
                variant="danger"
                onClick={() => void removeTrack(datasetId, t.id)}
                aria-label="Remove track"
              >
                <Trash2 size={15} strokeWidth={2.25} />
              </Button>
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
