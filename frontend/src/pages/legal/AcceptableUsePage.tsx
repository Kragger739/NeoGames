import { Link } from "react-router-dom";

import { LegalPage, LegalSection } from "../../components/LegalPage";

export function AcceptableUsePage() {
  return (
    <LegalPage title="Acceptable Use Policy">
      <p>
        This policy applies to everything you do on NeoGames — chat, voice,
        video, usernames, room names, and any content you create in Workshop. It
        forms part of the <Link to="/terms">Terms of Service</Link>.
      </p>

      <LegalSection heading="Content in Workshop question sets and playlists">
        <p>Do not create, upload, or import content that:</p>
        <ul>
          <li>is illegal, or promotes or facilitates illegal activity;</li>
          <li>
            is hateful, harassing, threatening, or discriminatory towards a
            person or group;
          </li>
          <li>is defamatory or knowingly false about a real person;</li>
          <li>
            is sexual content involving minors, or sexualises a real identifiable
            person without consent;
          </li>
          <li>
            contains another person&rsquo;s private information without their
            permission;
          </li>
          <li>
            infringes copyright, trademark, or other rights — including importing
            playlists or media you have no right to redistribute.
          </li>
        </ul>
      </LegalSection>

      <LegalSection heading="Conduct in rooms, chat, and on camera/microphone">
        <p>
          Treat other players with respect. Do not harass, bully, threaten, or
          expose others to sexual or violent content. Do not record, screenshot,
          re-stream, or otherwise capture another player&rsquo;s camera or
          microphone feed without their clear consent. Do not attempt to
          identify, dox, or follow players outside the game.
        </p>
      </LegalSection>

      <LegalSection heading="Technical abuse">
        <p>
          Do not probe, scan, or overload the service, bypass rate limits or
          access controls, scrape data, automate accounts, or interfere with
          other players&rsquo; sessions.
        </p>
      </LegalSection>

      <LegalSection heading="Reporting and enforcement">
        <p>
          Report abuse or policy breaches to{" "}
          <a href="mailto:support@neocodes.ch">support@neocodes.ch</a> with the
          room code, username, and a description (and a screenshot if you have
          one). We review reports and may remove content, issue warnings, or
          suspend or permanently ban accounts, at our discretion and taking
          severity into account. We may also report unlawful content to the
          authorities.
        </p>
      </LegalSection>

      <LegalSection heading="Repeat infringers">
        <p>
          Accounts that repeatedly breach this policy, or that are the subject of
          repeated valid copyright complaints, will be permanently terminated.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
