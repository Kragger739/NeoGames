import { useEffect, useRef } from "react";
import { UserX } from "lucide-react";

import { DdfHeartsRow } from "./DdfHeartsRow";
import { IconButton } from "../ui/IconButton";
import type { AnswerStatus } from "../../stores/ddfStore";
import type { DdfCycleAnswer } from "../../lib/ddfTypes";

interface DdfWebcamCardProps {
  name: string;
  stream: MediaStream | null;
  muted?: boolean;
  hearts?: number;
  isEliminated?: boolean;
  answerStatus?: AnswerStatus;
  isAnswering?: boolean;
  variant?: "gm" | "player";
  /** One dot per question this player was asked this voting cycle (green/red/grey). */
  dots?: DdfCycleAnswer[];
  onEliminate?: () => void;
}

const STATUS_LABEL: Record<AnswerStatus, string> = {
  pending: "🟢 Answering",
  submitted: "🟢 Answering",
  correct: "✅ Correct",
  wrong: "❌ Wrong",
};

/** One webcam tile - video, name, hearts, and a live status badge. */
export function DdfWebcamCard({
  name,
  stream,
  muted = false,
  hearts,
  isEliminated = false,
  answerStatus,
  isAnswering = false,
  variant = "player",
  dots,
  onEliminate,
}: DdfWebcamCardProps) {
  const videoRef = useRef<HTMLVideoElement>(null);

  useEffect(() => {
    if (videoRef.current) {
      videoRef.current.srcObject = stream;
    }
  }, [stream]);

  const classes = [
    "ddf-webcam-card",
    `ddf-webcam-card-${variant}`,
    isAnswering ? "ddf-webcam-card-answering" : "",
    isEliminated ? "ddf-webcam-card-eliminated" : "",
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <div className={classes}>
      <div className="ddf-webcam-video-wrap">
        {stream ? (
          <video ref={videoRef} autoPlay playsInline muted={muted} className="ddf-webcam-video" />
        ) : (
          <div className="ddf-webcam-placeholder" aria-hidden="true">
            📷
          </div>
        )}
        {dots && dots.length > 0 && (
          <div className="ddf-webcam-dots">
            {dots.map((d) => (
              <span
                key={d.questionNumber}
                className={`ddf-webcam-dot ddf-webcam-dot-${
                  d.isCorrect === true ? "correct" : d.isCorrect === false ? "wrong" : "grey"
                }`}
                title={d.questionText}
              />
            ))}
          </div>
        )}
        {onEliminate && !isEliminated && (
          <IconButton
            icon={UserX}
            label={`Eliminate ${name}`}
            variant="danger"
            className="ddf-webcam-eliminate-btn"
            onClick={onEliminate}
          />
        )}
        <div className="ddf-webcam-footer">
          {isEliminated && <span className="ddf-webcam-eliminated-badge">🔴 Eliminated</span>}
          {!isEliminated && answerStatus && answerStatus !== "pending" && (
            <span className="ddf-webcam-status-badge">{STATUS_LABEL[answerStatus]}</span>
          )}
          <div className="ddf-webcam-footer-row">
            <span className="ddf-webcam-name">{name}</span>
            {hearts !== undefined && <DdfHeartsRow hearts={hearts} />}
          </div>
        </div>
      </div>
    </div>
  );
}
