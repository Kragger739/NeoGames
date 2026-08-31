import { Route, Routes } from "react-router-dom";

import { RequireAdmin } from "./components/RequireAdmin";
import { RequireHost } from "./components/RequireHost";
import { RoomInviteToast } from "./components/RoomInviteToast";
import { SiteFooter } from "./components/SiteFooter";
import { AdminSeasonsPage } from "./pages/AdminSeasonsPage";
import { AdminSongPlaylistsPage } from "./pages/AdminSongPlaylistsPage";
import { AdminUnlocksPage } from "./pages/AdminUnlocksPage";
import { AdminUserDetailPage } from "./pages/AdminUserDetailPage";
import { AdminUsersPage } from "./pages/AdminUsersPage";
import { DdfGmPanelPage } from "./pages/DdfGmPanelPage";
import { DdfLandingPage } from "./pages/DdfLandingPage";
import { DdfLobbyPage } from "./pages/DdfLobbyPage";
import { DdfPlayOverlayPage } from "./pages/DdfPlayOverlayPage";
import { CosmeticsPage } from "./pages/CosmeticsPage";
import { DatasetEditorPage } from "./pages/DatasetEditorPage";
import { FriendsPage } from "./pages/FriendsPage";
import { GamePlayPage } from "./pages/GamePlayPage";
import { HomePage } from "./pages/HomePage";
import { JoinPage } from "./pages/JoinPage";
import { LeaderboardPage } from "./pages/LeaderboardPage";
import { LobbyPage } from "./pages/LobbyPage";
import { ProfilePage } from "./pages/ProfilePage";
import { ResultsPage } from "./pages/ResultsPage";
import { SonglePage } from "./pages/SonglePage";
import { WorkshopPage } from "./pages/WorkshopPage";
import { NotFoundPage } from "./pages/NotFoundPage";
import { ForgotPasswordPage } from "./pages/auth/ForgotPasswordPage";
import { LoginPage } from "./pages/auth/LoginPage";
import { RegisterPage } from "./pages/auth/RegisterPage";
import { ResetPasswordPage } from "./pages/auth/ResetPasswordPage";
import { VerifyEmailPage } from "./pages/auth/VerifyEmailPage";
import { AcceptableUsePage } from "./pages/legal/AcceptableUsePage";
import { CookiePolicyPage } from "./pages/legal/CookiePolicyPage";
import { CopyrightPage } from "./pages/legal/CopyrightPage";
import { LegalNoticePage } from "./pages/legal/LegalNoticePage";
import { PrivacyPolicyPage } from "./pages/legal/PrivacyPolicyPage";
import { TermsOfServicePage } from "./pages/legal/TermsOfServicePage";

function App() {
  return (
    <>
      <RoomInviteToast />
      <Routes>
        <Route
          path="/"
          element={
            <RequireHost>
              <HomePage />
            </RequireHost>
          }
        />
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/verify-email" element={<VerifyEmailPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />

        <Route path="/privacy" element={<PrivacyPolicyPage />} />
        <Route path="/terms" element={<TermsOfServicePage />} />
        <Route path="/cookies" element={<CookiePolicyPage />} />
        <Route path="/acceptable-use" element={<AcceptableUsePage />} />
        <Route path="/copyright" element={<CopyrightPage />} />
        <Route path="/legal" element={<LegalNoticePage />} />
        <Route
          path="/songle"
          element={
            <RequireHost>
              <SonglePage />
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
          path="/profile/cosmetics"
          element={
            <RequireHost>
              <CosmeticsPage />
            </RequireHost>
          }
        />
        <Route
          path="/leaderboard"
          element={
            <RequireHost>
              <LeaderboardPage />
            </RequireHost>
          }
        />
        <Route
          path="/workshop"
          element={
            <RequireHost>
              <WorkshopPage />
            </RequireHost>
          }
        />
        <Route
          path="/workshop/:id"
          element={
            <RequireHost>
              <DatasetEditorPage />
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
        <Route
          path="/admin"
          element={
            <RequireAdmin>
              <AdminUsersPage />
            </RequireAdmin>
          }
        />
        <Route
          path="/admin/users/:id"
          element={
            <RequireAdmin>
              <AdminUserDetailPage />
            </RequireAdmin>
          }
        />
        <Route
          path="/admin/song-playlists"
          element={
            <RequireAdmin>
              <AdminSongPlaylistsPage />
            </RequireAdmin>
          }
        />
        <Route
          path="/admin/unlocks"
          element={
            <RequireAdmin>
              <AdminUnlocksPage />
            </RequireAdmin>
          }
        />
        <Route
          path="/admin/seasons"
          element={
            <RequireAdmin>
              <AdminSeasonsPage />
            </RequireAdmin>
          }
        />
        <Route path="/play/:code" element={<JoinPage />} />
        <Route path="/rooms/:code/lobby" element={<LobbyPage />} />
        <Route path="/rooms/:code/play" element={<GamePlayPage />} />
        <Route path="/rooms/:code/results" element={<ResultsPage />} />

        <Route
          path="/ddf"
          element={
            <RequireHost>
              <DdfLandingPage />
            </RequireHost>
          }
        />
        <Route path="/ddf-rooms/:code/lobby" element={<DdfLobbyPage />} />
        <Route
          path="/ddf-rooms/:code/gm"
          element={
            <RequireHost>
              <DdfGmPanelPage />
            </RequireHost>
          }
        />
        <Route path="/ddf-rooms/:code/play" element={<DdfPlayOverlayPage />} />

        <Route path="*" element={<NotFoundPage />} />
      </Routes>
      <SiteFooter />
    </>
  );
}

export default App;
