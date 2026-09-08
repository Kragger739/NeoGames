import { useCallback, useEffect, useRef, useState } from "react";
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

/**
 * Horizontal, snap-scrolling artist picker. The card nearest the track's
 * centre is the selection: it scales up (both the "grows into focus" and the
 * "animates while scrolling" effect, since the scale transitions as the
 * centred card changes). Clicking a side card centres it; clicking the
 * centred card — or the Play button — starts a game.
 */
export function IconicArtistCarousel({ artists, onPick, disabled = false, busy = false }: Props) {
  const trackRef = useRef<HTMLDivElement | null>(null);
  const cardRefs = useRef<Map<number, HTMLButtonElement>>(new Map());
  const [centeredId, setCenteredId] = useState<number | null>(artists[0]?.id ?? null);
  const rafRef = useRef<number | null>(null);

  const recomputeCentered = useCallback(() => {
    const track = trackRef.current;
    if (!track) return;
    const mid = track.scrollLeft + track.clientWidth / 2;
    let bestId: number | null = null;
    let bestDist = Infinity;
    for (const [id, el] of cardRefs.current) {
      const cardMid = el.offsetLeft + el.offsetWidth / 2;
      const dist = Math.abs(cardMid - mid);
      if (dist < bestDist) {
        bestDist = dist;
        bestId = id;
      }
    }
    setCenteredId((prev) => (prev === bestId ? prev : bestId));
  }, []);

  function onScroll() {
    if (rafRef.current !== null) return;
    rafRef.current = requestAnimationFrame(() => {
      rafRef.current = null;
      recomputeCentered();
    });
  }

  useEffect(() => {
    recomputeCentered();
    window.addEventListener("resize", recomputeCentered);
    return () => {
      window.removeEventListener("resize", recomputeCentered);
      if (rafRef.current !== null) cancelAnimationFrame(rafRef.current);
    };
  }, [recomputeCentered, artists.length]);

  function scrollToCard(id: number) {
    cardRefs.current.get(id)?.scrollIntoView({ inline: "center", block: "nearest", behavior: "smooth" });
  }

  function step(dir: -1 | 1) {
    const ids = artists.map((a) => a.id);
    const i = centeredId != null ? ids.indexOf(centeredId) : 0;
    const next = ids[Math.min(ids.length - 1, Math.max(0, i + dir))];
    if (next != null) scrollToCard(next);
  }

  function activate(id: number) {
    if (disabled || busy) return;
    if (id === centeredId) onPick(id);
    else scrollToCard(id);
  }

  const centered = artists.find((a) => a.id === centeredId) ?? null;

  return (
    <div className="iconic-carousel">
      <div className="iconic-carousel-row">
        <IconButton
          icon={ChevronLeft}
          label="Previous artist"
          onClick={() => step(-1)}
          disabled={disabled || centeredId === artists[0]?.id}
        />
        <div className="iconic-carousel-track" ref={trackRef} onScroll={onScroll}>
          <span className="iconic-carousel-spacer" aria-hidden="true" />
          {artists.map((artist) => (
            <button
              key={artist.id}
              type="button"
              ref={(el) => {
                if (el) cardRefs.current.set(artist.id, el);
                else cardRefs.current.delete(artist.id);
              }}
              className={
                artist.id === centeredId
                  ? "iconic-artist-card is-centered"
                  : "iconic-artist-card"
              }
              onClick={() => activate(artist.id)}
              aria-current={artist.id === centeredId}
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
          onClick={() => step(1)}
          disabled={disabled || centeredId === artists[artists.length - 1]?.id}
        />
      </div>

      <Button
        variant="primary"
        size="lg"
        disabled={disabled || busy || centered === null}
        onClick={() => centered && onPick(centered.id)}
      >
        {busy ? (
          "Setting up…"
        ) : (
          <>
            <Play size={20} strokeWidth={2.5} />
            {centered ? `Play ${centered.name}` : "Play"}
          </>
        )}
      </Button>
    </div>
  );
}
