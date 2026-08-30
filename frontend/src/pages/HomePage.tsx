import { useEffect } from "react";
import { Link, useNavigate } from "react-router-dom";
import { Hammer, Trophy, UserRound, Users2 } from "lucide-react";

import { PLAYABLE_GAMES, LOCKED_GAMES } from "../lib/games";
import { useAuthStore } from "../stores/authStore";
import { useFriendsStore } from "../stores/friendsStore";
import { Avatar } from "../components/ui/Avatar";
import { Button } from "../components/ui/Button";
import { Badge } from "../components/ui/Badge";
import { PartyNote } from "../components/illustrations/PartyNote";

export function HomePage() {
  const host = useAuthStore((state) => state.host);
  const logout = useAuthStore((state) => state.logout);
  const navigate = useNavigate();
  const friendsStatus = useFriendsStore((state) => state.status);
  const fetchFriends = useFriendsStore((state) => state.fetch);
  const connectFriendNotifications = useFriendsStore((state) => state.connectNotifications);
  const pendingRequestCount = useFriendsStore((state) => state.incomingRequests.length);

  useEffect(() => {
    if (friendsStatus === "idle") {
      void fetchFriends();
    }
    // Makes the badge below live without ever having visited /friends -
    // same notification the Friends page itself listens for.
    connectFriendNotifications();
  }, [friendsStatus, fetchFriends, connectFriendNotifications]);

  async function handleLogout() {
    await logout();
    navigate("/login");
  }

  return (
    <div className="home-page">
      <h1>Hey, {host?.name}!</h1>
      {host && <Avatar data={host.avatar} size="sm" />}
      {host && (
        <p className="hint">
          <Badge tone="grape">Lvl {host.level}</Badge> {host.xp} XP
        </p>
      )}

      <div className="game-grid">
        {PLAYABLE_GAMES.map((game) => {
          const Icon = game.icon;
          return (
            <Link key={game.id} to={game.route!} className="card card-tint-turquoise game-tile">
              {Icon ? (
                <Icon className="game-tile-icon-lucide game-tile-icon-lucide-playable" strokeWidth={2} />
              ) : (
                <PartyNote className="game-tile-icon" />
              )}
              <h3 className="game-tile-label">{game.label}</h3>
              <p className="hint">{game.description}</p>
            </Link>
          );
        })}
        {LOCKED_GAMES.map((game) => {
          const Icon = game.icon!;
          return (
            <div key={game.id} className="card game-tile game-tile-locked" aria-disabled="true">
              <Icon className="game-tile-icon-lucide" strokeWidth={2} />
              <h3 className="game-tile-label">{game.label}</h3>
              <p className="hint">{game.description}</p>
            </div>
          );
        })}
      </div>

      <nav>
        <Link to="/profile">
          <UserRound size={16} strokeWidth={2.25} />
          Profile
        </Link>
        <Link to="/leaderboard">
          <Trophy size={16} strokeWidth={2.25} />
          Leaderboard
        </Link>
        <Link to="/workshop">
          <Hammer size={16} strokeWidth={2.25} />
          Workshop
        </Link>
        <Link to="/friends">
          <Users2 size={16} strokeWidth={2.25} />
          Friends
          {pendingRequestCount > 0 && <Badge tone="coral">{pendingRequestCount}</Badge>}
        </Link>
      </nav>
      <Button variant="ghost" onClick={handleLogout}>
        Log out
      </Button>
    </div>
  );
}
