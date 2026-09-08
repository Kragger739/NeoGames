import { useEffect } from "react";
import { Link } from "react-router-dom";

import { useShopStore } from "../stores/shopStore";
import { Badge } from "../components/ui/Badge";
import { Button } from "../components/ui/Button";

export function ShopPage() {
  const { status, neoCoins, freeWeekEndsAt, artists, error, buyingId, fetch, buy } = useShopStore();

  useEffect(() => {
    void fetch();
  }, [fetch]);

  return (
    <div className="shop-page">
      <p>
        <Link to="/">← Home</Link>
      </p>
      <div className="shop-header">
        <h1>Shop</h1>
        <Badge tone="turquoise">◈ {neoCoins} NeoCoins</Badge>
      </div>
      <p className="hint">
        Unlock iconic artists to play their five-song rounds. Earn NeoCoins by levelling up.
      </p>
      {freeWeekEndsAt && (
        <p className="hint">
          One priced artist is free to play every week — the pick rotates{" "}
          {new Date(freeWeekEndsAt).toLocaleDateString(undefined, { month: "short", day: "numeric" })}.
        </p>
      )}

      {error && <p className="form-error">{error}</p>}

      {status !== "ready" && artists.length === 0 ? (
        <p className="hint">Loading…</p>
      ) : artists.length === 0 ? (
        <p className="hint">No artists in the shop yet.</p>
      ) : (
        <ul className="shop-grid">
          {artists.map((artist) => {
            const free = artist.price === 0;
            return (
              <li key={artist.id} className="card shop-card">
                {artist.image_url ? (
                  <img src={artist.image_url} alt="" className="shop-card-photo" />
                ) : (
                  <span className="shop-card-photo art-placeholder" aria-hidden="true" />
                )}
                <h3 className="shop-card-name">{artist.name}</h3>
                {artist.free_this_week && !artist.owned && (
                  <Badge tone="turquoise">Free this week</Badge>
                )}
                {artist.owned ? (
                  <Badge tone="grape">{free ? "Free" : "Owned"}</Badge>
                ) : (
                  <Button
                    variant="primary"
                    disabled={buyingId === artist.id}
                    onClick={() => void buy(artist.id)}
                  >
                    {buyingId === artist.id ? "Buying…" : `Buy · ◈ ${artist.price}`}
                  </Button>
                )}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
