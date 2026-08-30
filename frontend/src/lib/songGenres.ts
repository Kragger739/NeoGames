import type { SongGenre } from "./roomTypes";

/** Safety cap on how many artists a Multi-artist room can list - mirrors backend's SongGenre::MAX_MULTI_ARTIST_COUNT. */
export const MULTI_ARTIST_MAX = 10;

export const SONG_GENRES: { value: SongGenre; label: string; description: string }[] = [
  {
    value: "normal",
    label: "Normal",
    description: "No genre filter - today's default mix.",
  },
  {
    value: "pop",
    label: "Pop",
    description: "Best-effort pop-tagged songs only - may take a little longer to find a match.",
  },
  {
    value: "hip_hop",
    label: "Hip-hop",
    description: "Best-effort hip-hop/rap-tagged songs only - may take a little longer to find a match.",
  },
  {
    value: "german_rap",
    label: "German rap",
    description: "German-language rap only - may take a little longer to find a match.",
  },
  {
    value: "artist",
    label: "Artist",
    description: "Only songs by one artist you pick.",
  },
  {
    value: "multi_artist",
    label: "Multi-artist",
    description: "Only songs from several artists you pick.",
  },
  {
    value: "classics",
    label: "Classics",
    description: "Old, still-famous hits - opens the pool up to songs from 1950 onward.",
  },
  {
    value: "year",
    label: "Year range",
    description: "Only songs released in the range you pick below.",
  },
];
