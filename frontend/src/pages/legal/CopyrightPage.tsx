import { LegalPage, LegalSection } from "../../components/LegalPage";

export function CopyrightPage() {
  return (
    <LegalPage title="Copyright &amp; Takedown">
      <p>
        Neocodes respects intellectual property rights and expects NeoGames
        users to do the same. This page explains where music and artwork come
        from and how to ask for content to be removed.
      </p>

      <LegalSection heading="Where music and artwork come from">
        <p>
          Song previews (about 30 seconds) and cover art shown in NeoGames are
          retrieved at play time through the Deezer API and remain the property
          of their respective rights holders. They are used for personal,
          non-commercial gameplay. NeoGames does not host full tracks or offer
          downloads. Users can also import Deezer playlists into their own
          Workshop content.
        </p>
      </LegalSection>

      <LegalSection heading="Submitting a takedown notice">
        <p>
          If you own or represent the owner of rights in content that appears in
          NeoGames (including user-imported playlists or Workshop questions) and
          you believe it is used without authorisation, email{" "}
          <a href="mailto:support@neocodes.ch">support@neocodes.ch</a> with the
          subject line &ldquo;Copyright notice&rdquo; and include:
        </p>
        <ul>
          <li>your name and, if acting for someone else, who you represent;</li>
          <li>
            identification of the work and where it appears in NeoGames (a room
            code, Workshop dataset link, screenshot, or track details);
          </li>
          <li>
            a statement that you have a good-faith belief the use is not
            authorised by the rights holder or the law;
          </li>
          <li>
            a statement that the information in your notice is accurate and that
            you are authorised to act;
          </li>
          <li>your contact details and signature (typed is fine).</li>
        </ul>
        <p>
          We aim to acknowledge notices within 5 business days and to remove or
          disable access to validly identified content promptly.
        </p>
      </LegalSection>

      <LegalSection heading="Counter-notice">
        <p>
          If your content was removed and you believe that was a mistake or that
          you have the necessary rights, reply to our removal email with an
          explanation and your contact details. We will consider it and, where
          appropriate, restore the content.
        </p>
      </LegalSection>

      <LegalSection heading="Repeat-infringer policy">
        <p>
          Accounts that are the subject of repeated valid copyright notices will
          be suspended and may be permanently terminated.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
