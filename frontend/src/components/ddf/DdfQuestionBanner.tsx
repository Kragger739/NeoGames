const CATEGORY_ICON: Record<string, string> = {
  history: "🏛️",
  geography: "🌍",
  science: "🔬",
  math: "🔢",
  sports: "⚽",
  movies_tv: "🎬",
  music: "🎵",
  animals: "🐾",
  technology: "💻",
  culture: "🎭",
  everyday_knowledge: "💡",
};

interface DdfQuestionBannerProps {
  category: string;
  text: string;
}

/** The large bottom banner holding the current question - stays readable over a busy webcam grid. */
export function DdfQuestionBanner({ category, text }: DdfQuestionBannerProps) {
  return (
    <div className="ddf-question-banner">
      <span className="ddf-question-banner-label">
        {CATEGORY_ICON[category] ?? "❓"} QUESTION
      </span>
      <p className="ddf-question-banner-text">{text}</p>
    </div>
  );
}
