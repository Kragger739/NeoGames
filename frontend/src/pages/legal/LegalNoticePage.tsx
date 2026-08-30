import { LegalPage, LegalSection } from "../../components/LegalPage";

export function LegalNoticePage() {
  return (
    <LegalPage title="Legal Notice">
      <LegalSection heading="Operator">
        <p>NeoGames is operated by Neocodes.</p>
      </LegalSection>

      <LegalSection heading="Location">
        <p>
          Switzerland. Our full postal address is available on request by email.
        </p>
      </LegalSection>

      <LegalSection heading="Contact">
        <p>
          Email: <a href="mailto:support@neocodes.ch">support@neocodes.ch</a>
          <br />
          This address is monitored for support, privacy, and legal enquiries
          and is a working way to reach us directly.
        </p>
      </LegalSection>

      <LegalSection heading="Commercial register / VAT">
        <p>
          Neocodes is a small independent operator. If a Swiss commercial
          register entry (UID/CHE number) or VAT registration applies, it will
          be listed here.
        </p>
      </LegalSection>

      <LegalSection heading="Responsibility for content">
        <p>
          Neocodes is responsible for the content it publishes on NeoGames.
          Content created by users (Workshop question sets and playlists, room
          and profile names, chat, and live audio/video) is the responsibility
          of the users who create it; report problems to the contact address
          above.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
