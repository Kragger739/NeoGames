import { Link } from "react-router-dom";

import { LegalPage, LegalSection } from "../../components/LegalPage";

export function TermsOfServicePage() {
  return (
    <LegalPage title="Terms of Service">
      <p>
        These terms govern your use of NeoGames, operated by{" "}
        <strong>Neocodes</strong>, Switzerland. By creating an account or joining
        a room you agree to them. If you do not agree, do not use NeoGames.
      </p>

      <LegalSection heading="Eligibility and minimum age">
        <p>
          You must be at least <strong>13 years old</strong> to use NeoGames,
          whether as a registered host or as a guest joining through an invite
          link. If you are under the age of majority where you live, you may use
          NeoGames only with the involvement of a parent or guardian, who
          accepts these terms on your behalf. We may remove accounts we
          reasonably believe belong to someone under 13.
        </p>
      </LegalSection>

      <LegalSection heading="Your account and security">
        <p>
          Keep your login credentials confidential and use a password you do not
          reuse elsewhere. You are responsible for activity under your account.
          Tell us at <a href="mailto:support@neocodes.ch">support@neocodes.ch</a>{" "}
          if you think it has been compromised. One person, one account; do not
          impersonate others.
        </p>
      </LegalSection>

      <LegalSection heading="Acceptable use">
        <p>
          Your use of NeoGames — including chat, voice, video, and any content
          you create in Workshop — must follow our{" "}
          <Link to="/acceptable-use">Acceptable Use Policy</Link>. We may
          suspend or terminate accounts that breach it.
        </p>
      </LegalSection>

      <LegalSection heading="Content you create">
        <p>
          You keep ownership of the question sets, playlists, and other content
          you create. You grant Neocodes a worldwide, non-exclusive,
          royalty-free licence to host, store, reproduce, and display that
          content as needed to operate NeoGames, including showing content you
          mark as public to other users. You are responsible for having the
          rights to everything you upload, and you must not upload content that
          infringes someone else&rsquo;s rights or breaks the law.
        </p>
      </LegalSection>

      <LegalSection heading="Third-party music previews and trademarks">
        <p>
          Song previews are provided through Apple&rsquo;s iTunes Search API,
          and track data and cover art through the Spotify Web API; all of it
          remains the property of the respective rights holders. It is made
          available for personal, non-commercial gameplay only. Artist, album,
          and service names and logos are the trademarks of their owners. If you
          hold rights in content shown in NeoGames and want it removed, see our{" "}
          <Link to="/copyright">Copyright &amp; Takedown</Link> page.
        </p>
      </LegalSection>

      <LegalSection heading="Service availability and changes">
        <p>
          NeoGames is provided on an &ldquo;as is&rdquo; and &ldquo;as
          available&rdquo; basis. We may change, suspend, or discontinue
          features at any time, and we do not guarantee uninterrupted or
          error-free operation.
        </p>
      </LegalSection>

      <LegalSection heading="Disclaimers and limitation of liability">
        <p>
          To the fullest extent permitted by Swiss law, Neocodes is not liable
          for indirect or consequential damages, for lost data or profits, or
          for the conduct of other users (including anything said or shown on
          camera or microphone during a game). Nothing in these terms limits
          liability that cannot be limited by law, such as for intent or gross
          negligence.
        </p>
      </LegalSection>

      <LegalSection heading="Governing law and jurisdiction">
        <p>
          These terms are governed by Swiss law, excluding its conflict-of-law
          rules and the UN Convention on Contracts for the International Sale of
          Goods. The exclusive place of jurisdiction is the ordinary courts at
          the registered seat of Neocodes in Switzerland, subject to any
          mandatory place of jurisdiction that protects you as a consumer.
        </p>
      </LegalSection>

      <LegalSection heading="Suspension and termination">
        <p>
          You may stop using NeoGames and delete your account at any time under
          Profile → Delete account. We may suspend or terminate your access if
          you breach these terms or the Acceptable Use Policy, or where required
          by law.
        </p>
      </LegalSection>

      <LegalSection heading="Contact">
        <p>
          Questions about these terms:{" "}
          <a href="mailto:support@neocodes.ch">support@neocodes.ch</a>.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
