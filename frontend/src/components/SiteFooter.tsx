import { Link, useLocation } from "react-router-dom";

// Immersive game surfaces render their own full-viewport layout - the
// footer would only get in the way there.
const HIDDEN_PREFIXES = ["/rooms/", "/ddf-rooms/", "/play/"];

export function SiteFooter() {
  const { pathname } = useLocation();

  if (HIDDEN_PREFIXES.some((prefix) => pathname.startsWith(prefix))) {
    return null;
  }

  return (
    <footer className="site-footer">
      <nav>
        <Link to="/privacy">Privacy</Link>
        <Link to="/terms">Terms</Link>
        <Link to="/cookies">Cookies</Link>
        <Link to="/acceptable-use">Acceptable Use</Link>
        <Link to="/copyright">Copyright</Link>
        <Link to="/legal">Legal Notice</Link>
      </nav>
      <p className="hint">
        NeoGames is operated by Neocodes, Switzerland. © {new Date().getFullYear()}{" "}
        Neocodes. Music previews and cover art via Deezer.
      </p>
    </footer>
  );
}
