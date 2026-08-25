import { Navigate, Route, Routes } from "react-router-dom";

import { RequireHost } from "./components/RequireHost";
import { RoomInviteToast } from "./components/RoomInviteToast";
import { DashboardPage } from "./pages/DashboardPage";
import { FriendsPage } from "./pages/FriendsPage";
import { GamePlayPage } from "./pages/GamePlayPage";
import { JoinPage } from "./pages/JoinPage";
import { LobbyPage } from "./pages/LobbyPage";
import { ProfilePage } from "./pages/ProfilePage";
import { ResultsPage } from "./pages/ResultsPage";
import { LoginPage } from "./pages/auth/LoginPage";
import { RegisterPage } from "./pages/auth/RegisterPage";

function App() {
  return (
    <>
      <RoomInviteToast />
      <Routes>
        <Route path="/" element={<Navigate to="/dashboard" replace />} />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route
          path="/dashboard"
          element={
            <RequireHost>
              <DashboardPage />
            </RequireHost>
          }
        />
        <Route
          path="/profile"
          element={
            <RequireHost>
              <ProfilePage />
            </RequireHost>
          }
        />
        <Route
          path="/friends"
          element={
            <RequireHost>
              <FriendsPage />
            </RequireHost>
          }
        />
        <Route path="/play/:code" element={<JoinPage />} />
        <Route path="/rooms/:code/lobby" element={<LobbyPage />} />
        <Route path="/rooms/:code/play" element={<GamePlayPage />} />
        <Route path="/rooms/:code/results" element={<ResultsPage />} />
      </Routes>
    </>
  );
}

export default App;
