// Workshop / Creator - custom datasets for DDF & Songle. Mirrors the
// DatasetController payloads on the backend.

export type DatasetType = "ddf" | "songle";
export type DatasetVisibility = "private" | "public";
export type DatasetLanguage = "en" | "de";

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
  deezer_track_id: string;
  title: string;
  artist: string;
  album_art_url: string | null;
  position: number;
}

export interface DatasetDetail extends DatasetSummary {
  owner_id: number;
  language: DatasetLanguage | null;
  questions?: DatasetQuestion[];
  tracks?: DatasetTrack[];
}

export interface DatasetsIndex {
  mine: DatasetSummary[];
  community: DatasetSummary[];
}
