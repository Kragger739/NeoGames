import { Link } from "react-router-dom";

import { LegalPage, LegalSection } from "../../components/LegalPage";

export function CookiePolicyPage() {
  return (
    <LegalPage title="Cookie Policy">
      <p>
        NeoGames uses a small number of cookies and browser-storage items. All
        of them are strictly necessary to sign you in and run a game, so no
        consent banner is shown. We do not use advertising or analytics cookies.
      </p>

      <LegalSection heading="What we store on your device">
        <table className="legal-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Type</th>
              <th>Purpose</th>
              <th>Retention</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><code>neogames-session</code></td>
              <td>Cookie (HttpOnly)</td>
              <td>Keeps you signed in during a visit</td>
              <td>120 minutes from last activity</td>
            </tr>
            <tr>
              <td><code>XSRF-TOKEN</code></td>
              <td>Cookie</td>
              <td>Protects form and API requests against cross-site request forgery</td>
              <td>120 minutes from last activity</td>
            </tr>
            <tr>
              <td><code>remember_web_*</code></td>
              <td>Cookie</td>
              <td>&ldquo;Remember me&rdquo; persistent login — only set if you tick that option</td>
              <td>About 400 days, or until you log out</td>
            </tr>
            <tr>
              <td>volume, muted</td>
              <td>localStorage</td>
              <td>Remembers your in-game sound level and mute setting</td>
              <td>Until you clear your browser storage</td>
            </tr>
            <tr>
              <td>player token / player id</td>
              <td>sessionStorage</td>
              <td>Identifies you as a guest player within a single room</td>
              <td>Until the browser tab is closed</td>
            </tr>
          </tbody>
        </table>
      </LegalSection>

      <LegalSection heading="Third-party requests">
        <p>
          Loading a NeoGames page also causes your browser to contact Google
          Fonts (<code>fonts.googleapis.com</code>,{" "}
          <code>fonts.gstatic.com</code>) for typefaces, and — while playing
          &ldquo;Der Dümmste fliegt&rdquo; — a Google STUN server to set up the
          video connection. These receive your IP address but set no cookies of
          their own through NeoGames. Song previews and artwork are loaded
          directly from Deezer.
        </p>
      </LegalSection>

      <LegalSection heading="Managing cookies">
        <p>
          You can delete or block cookies in your browser settings, but NeoGames
          will not be able to keep you signed in without the session and
          CSRF cookies above. For more on the data involved, see our{" "}
          <Link to="/privacy">Privacy Policy</Link>.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
