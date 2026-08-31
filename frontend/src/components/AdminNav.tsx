import { CalendarDays, Home, ListMusic, Lock, Users } from "lucide-react";
import { NavLink } from "react-router-dom";

const SECTIONS = [
  { to: "/admin", label: "Users", icon: Users },
  { to: "/admin/song-playlists", label: "Song playlists", icon: ListMusic },
  { to: "/admin/unlocks", label: "Unlocks & Daily", icon: Lock },
  { to: "/admin/seasons", label: "Seasons & Battlepass", icon: CalendarDays },
] as const;

/** The shared pill-button nav across every admin page. */
export function AdminNav() {
  return (
    <nav className="admin-nav">
      <NavLink to="/" className="admin-nav-home">
        <Home size={16} strokeWidth={2.25} />
        Home
      </NavLink>
      {SECTIONS.map(({ to, label, icon: Icon }) => (
        <NavLink
          key={to}
          to={to}
          // "/admin" is a prefix of every admin route - without `end` the
          // Users chip stays highlighted on every page.
          end={to === "/admin"}
          className={({ isActive }) => (isActive ? "is-active" : undefined)}
        >
          <Icon size={16} strokeWidth={2.25} />
          {label}
        </NavLink>
      ))}
    </nav>
  );
}
