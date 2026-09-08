import { useEffect, useRef, useState } from "react";
import { ChevronLeft, ChevronRight, Play } from "lucide-react";

import type { IconicArtist } from "../lib/iconicSeries";
import { Button } from "./ui/Button";
import { IconButton } from "./ui/IconButton";

interface Props {
  artists: IconicArtist[];
  onPick: (id: number) => void;
  disabled?: boolean;
  busy?: boolean;
}

function reducedMotion(): boolean {
  return window.matchMedia?.("(prefers-reduced-motion: reduce)")?.matches ?? false;
}

const clamp = (n: number, min: number, max: number) => Math.min(max, Math.max(min, n));

/**
 * Horizontal artist picker. `selectedIndex` is the single source of truth:
 * the selected card is scrolled to dead centre and scaled up, the Play
 * button + chevrons act on it, and a manual swipe reconciles the index back
 * from the scroll position. Clicking a side card selects it; clicking the
 * selected card (or Play) starts a game.
 */
export function IconicArtistCarousel({ artists, onPick, disabled = false, busy = false }: Props) {
  const trackRef = useRef<HTMLDivElement | null>(null);
  const cardRefs = useRef<(HTMLButtonElement | null)[]>([]);
  const scrollDebounce = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [selectedIndex, setSelectedIndex] = useState(0);

  const lastIndex = Math.max(0, artists.length - 1);
  const selected = artists[selectedIndex] ?? null;

  // Keep the index in range if the artist list shrinks.
  useEffect(() => {
    setSelectedIndex((i) => Math.min(i, Math.max(0, artists.length - 1)));
  }, [artists.length]);

  // Bring the selected card to the centre (also runs on mount for index 0).
  useEffect(() => {
    cardRefs.current[selectedIndex]?.scrollIntoView({
      inline: "center",
      block: "nearest",
      behavior: reducedMotion() ? "auto" : "smooth",
    });
  }, [selectedIndex, artists.length]);

  useEffect(() => {
    return () => {
      if (scrollDebounce.current) clearTimeout(scrollDebounce.current);
    };
  }, []);

  // After a manual scroll/swipe settles, snap the index to whichever card is
  // nearest the track's centre. Idempotent after our own programmatic scroll.
  function onScroll() {
    if (scrollDebounce.current) clearTimeout(scrollDebounce.current);
    scrollDebounce.current = setTimeout(() => {
      const track = trackRef.current;
      if (!track) return;
      const trackMid = track.getBoundingClientRect().left + track.clientWidth / 2;
      let nearest = 0;
      let best = Infinity;
      cardRefs.current.forEach((el, i) => {
        if (!el) return;
        const rect = el.getBoundingClientRect();
        const dist = Math.abs(rect.left + rect.width / 2 - trackMid);
        if (dist < best) {
          best = dist;
          nearest = i;
        }
      });
      setSelectedIndex((prev) => (nearest !== prev ? nearest : prev));
    }, 110);
  }

  function handleCard(i: number) {
    if (disabled || busy) return;
    if (i === selectedIndex) {
      if (selected) onPick(selected.id);
    } else {
      setSelectedIndex(i);
    }
  }

  return (
    <div className="iconic-carousel">
      <div className="iconic-carousel-row">
        <IconButton
          icon={ChevronLeft}
          label="Previous artist"
          onClick={() => setSelectedIndex((i) => clamp(i - 1, 0, lastIndex))}
          disabled={disabled || selectedIndex === 0}
        />
        <div className="iconic-carousel-track" ref={trackRef} onScroll={onScroll}>
          <span className="iconic-carousel-spacer" aria-hidden="true" />
          {artists.map((artist, i) => (
            <button
              key={artist.id}
              type="button"
              ref={(el) => {
                cardRefs.current[i] = el;
              }}
              className={i === selectedIndex ? "iconic-artist-card is-centered" : "iconic-artist-card"}
              onClick={() => handleCard(i)}
              aria-current={i === selectedIndex}
              disabled={disabled}
            >
              {artist.image_url ? (
                <img src={artist.image_url} alt="" className="iconic-artist-photo" />
              ) : (
                <span className="iconic-artist-photo art-placeholder" aria-hidden="true" />
              )}
              <span className="iconic-artist-name">{artist.name}</span>
            </button>
          ))}
          <span className="iconic-carousel-spacer" aria-hidden="true" />
        </div>
        <IconButton
          icon={ChevronRight}
          label="Next artist"
          onClick={() => setSelectedIndex((i) => clamp(i + 1, 0, lastIndex))}
          disabled={disabled || selectedIndex >= lastIndex}
        />
      </div>

      <Button
        variant="primary"
        size="lg"
        disabled={disabled || busy || !selected}
        onClick={() => selected && onPick(selected.id)}
      >
        {busy ? (
          "Setting up…"
        ) : (
          <>
            <Play size={20} strokeWidth={2.5} />
            {selected ? `Play ${selected.name}` : "Play"}
          </>
        )}
      </Button>
    </div>
  );
}
