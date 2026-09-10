// Workshop / Creator - custom datasets for DDF & Songle. Mirrors the
// DatasetController payloads on the backend.

export type DatasetType = "ddf" | "songle" | "dub";
export type DatasetVisibility = "private" | "public";
export type DatasetLanguage = "en" | "de";
export type DatasetReviewStatus = "draft" | "pending" | "approved" | "rejected";

export type DdfCategory =
  | "history"
  | "geography"
  | "science"
  | "math"
  | "sports"
  | "movies_tv"
  | "music"
  | "animals"
  | "technology"
  | "culture"
  | "everyday_knowledge";

export const DDF_CATEGORIES: DdfCategory[] = [
  "history",
  "geography",
  "science",
  "math",
  "sports",
  "movies_tv",
  "music",
  "animals",
  "technology",
  "culture",
  "everyday_knowledge",
];

export function categoryLabel(category: DdfCategory): string {
  return category.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

export interface DatasetSummary {
  id: number;
  name: string;
  type: DatasetType;
  visibility: DatasetVisibility;
  review_status: DatasetReviewStatus;
  item_count: number;
  updated_at: string;
  owner_username: string | null;
}

export interface DatasetQuestion {
  id: number;
  text: string;
  correct_answer: string;
  category: DdfCategory;
  position: number;
}

export interface DatasetTrack {
  id: number;
  provider_track_id: string;
  title: string;
  artist: string;
  album_art_url: string | null;
  position: number;
}

export interface DubPackClip {
  id: number;
  title: string;
  status: string;
  video_url: string | null;
  source_url: string | null;
  processing_error: string | null;
  character_count: number;
  line_count: number;
  duration_ms: number | null;
  position: number;
}

export interface DatasetDetail extends DatasetSummary {
  owner_id: number;
  language: DatasetLanguage | null;
  review_note: string | null;
  questions?: DatasetQuestion[];
  tracks?: DatasetTrack[];
  dub_clips?: DubPackClip[];
}

export interface DatasetsIndex {
  mine: DatasetSummary[];
  community: DatasetSummary[];
}
