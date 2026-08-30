import { Link } from "react-router-dom";

import { LegalPage, LegalSection } from "../../components/LegalPage";

export function PrivacyPolicyPage() {
  return (
    <LegalPage title="Privacy Policy">
      <p>
        This policy explains how <strong>Neocodes</strong> (&ldquo;we&rdquo;,
        &ldquo;us&rdquo;) handles personal data when you use NeoGames. It is
        written to meet the Swiss Federal Act on Data Protection (revFADP) and,
        for players in the EU/EEA, the GDPR.
      </p>

      <LegalSection heading="Who we are and how to contact us">
        <p>
          Controller: Neocodes, Switzerland. For any privacy question or to
          exercise your rights, email{" "}
          <a href="mailto:support@neocodes.ch">support@neocodes.ch</a>. Our full
          postal address is available on request and in the{" "}
          <Link to="/legal">Legal Notice</Link>.
        </p>
      </LegalSection>

      <LegalSection heading="Data we collect">
        <ul>
          <li>
            <strong>Account:</strong> your name, username, email address,
            password (stored only as a bcrypt hash), and email-verification
            status.
          </li>
          <li>
            <strong>Profile:</strong> a profile photo if you upload one, your
            experience points (XP) and level, and equipped cosmetic items.
          </li>
          <li>
            <strong>Sign-in with Google or Discord:</strong> if you use it, we
            receive and store your email address, display name, and the
            provider&rsquo;s user ID. Accounts are matched by email address.
          </li>
          <li>
            <strong>Social features:</strong> your friends list and friend
            requests, the game rooms you host or join, the nicknames used by
            players in a room, and your public leaderboard entry (username and
            XP).
          </li>
          <li>
            <strong>Workshop content:</strong> the custom question sets and
            playlists you create, including any playlist you import.
          </li>
          <li>
            <strong>Technical/session data:</strong> for each login session we
            store a session identifier together with your IP address, browser
            user-agent, and last-activity time.
          </li>
          <li>
            <strong>&ldquo;Der Dümmste fliegt&rdquo; live audio/video:</strong>{" "}
            when you play this game your camera and microphone are streamed
            directly (peer-to-peer) to the other players in the room. These
            streams pass through your browser and the other players&rsquo;
            browsers. We do not record, store, or have access to them. A Google
            STUN server (<code>stun.l.google.com</code>) is contacted to
            establish the connection and receives your IP address.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Why we use your data and our legal bases">
        <ul>
          <li>
            <strong>To provide the service</strong> — creating your account,
            running games, showing friends and leaderboards. Basis: performance
            of our terms with you (GDPR Art. 6(1)(b); revFADP contractual
            necessity).
          </li>
          <li>
            <strong>Security and abuse prevention</strong> — email verification,
            login rate-limiting, session records, moderation. Basis: our
            legitimate interest in a safe service (GDPR Art. 6(1)(f)).
          </li>
          <li>
            <strong>Communicating with you</strong> — verification codes,
            password-reset emails, and service notices. Basis: contract and
            legitimate interest.
          </li>
          <li>
            <strong>Live camera/microphone in DDF</strong> — only when you
            actively join that game and grant your browser&rsquo;s permission.
            Basis: your consent (GDPR Art. 6(1)(a) / Art. 9(2)(a)); you can
            withdraw it by leaving the game and revoking the browser permission.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Third parties and international transfers">
        <p>
          We share data with the following processors and services only as
          needed to run NeoGames. Some are located outside Switzerland/the EU;
          where that is the case we rely on the provider&rsquo;s standard
          contractual clauses or an adequacy decision.
        </p>
        <ul>
          <li>
            <strong>Hosting provider</strong> — operates our servers and
            database.
          </li>
          <li>
            <strong>Email provider (SMTP)</strong> — delivers verification and
            password-reset emails; receives your email address.
          </li>
          <li>
            <strong>Apple (iTunes)</strong> — supplies 30-second song previews.
            Your browser loads the audio directly from Apple&rsquo;s servers,
            which receive your IP address.
          </li>
          <li>
            <strong>Spotify</strong> — supplies track and artist data and cover
            art. Your browser loads cover images directly from Spotify&rsquo;s
            servers, which receive your IP address.
          </li>
          <li>
            <strong>Google and Discord</strong> — only if you choose social
            sign-in.
          </li>
          <li>
            <strong>Google Fonts</strong> — fonts are currently loaded from
            <code> fonts.googleapis.com</code> / <code>fonts.gstatic.com</code>,
            which receive your IP address and user-agent on each page load.
          </li>
          <li>
            <strong>Google STUN</strong> — used for DDF video connections as
            described above.
          </li>
        </ul>
        <p>
          We do not sell your personal data and do not use it for advertising.
          NeoGames contains no analytics or tracking tools.
        </p>
      </LegalSection>

      <LegalSection heading="Cookies and local storage">
        <p>
          NeoGames uses only strictly-necessary cookies and browser storage.
          See the <Link to="/cookies">Cookie Policy</Link> for the full list and
          retention periods.
        </p>
      </LegalSection>

      <LegalSection heading="How long we keep data">
        <ul>
          <li>
            <strong>Account and profile data:</strong> until you delete your
            account (Profile → Delete account), after which it is removed,
            together with your friendships, hosted rooms, and Workshop content.
            Rows that anonymously record other players&rsquo; game history are
            kept with your identifier removed.
          </li>
          <li>
            <strong>Session records:</strong> pruned automatically after they
            expire (session inactivity timeout is 120 minutes).
          </li>
          <li>
            <strong>Email verification codes:</strong> 15 minutes.
            <strong> Password-reset tokens:</strong> 60 minutes.
          </li>
          <li>
            <strong>Server logs:</strong> kept only for a short period for
            security and debugging, then rotated.
          </li>
          <li>
            <strong>DDF audio/video:</strong> not retained by us at all.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Your rights">
        <p>
          Subject to the revFADP and the GDPR, you may request access to your
          data, correction of inaccurate data, deletion, restriction of
          processing, and a portable copy of the data you provided. You may
          object to processing based on legitimate interests and withdraw any
          consent at any time. You can delete your account yourself at any time
          under <strong>Profile → Delete account</strong>. For anything else,
          email <a href="mailto:support@neocodes.ch">support@neocodes.ch</a>; we
          respond within 30 days.
        </p>
      </LegalSection>

      <LegalSection heading="Children">
        <p>
          NeoGames is not intended for children under 13, and you must be at
          least 13 to create an account or join a room (see the{" "}
          <Link to="/terms">Terms of Service</Link>). If you believe a child
          under 13 has given us personal data, contact{" "}
          <a href="mailto:support@neocodes.ch">support@neocodes.ch</a> and we
          will delete it.
        </p>
      </LegalSection>

      <LegalSection heading="How to complain">
        <p>
          You can lodge a complaint with the Swiss Federal Data Protection and
          Information Commissioner (FDPIC), or, if you are in the EU/EEA, with
          your local data protection supervisory authority.
        </p>
      </LegalSection>

      <LegalSection heading="Changes to this policy">
        <p>
          We may update this policy as NeoGames evolves. Material changes will
          be announced in the app or by email, and the &ldquo;last
          updated&rdquo; date above will change.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
