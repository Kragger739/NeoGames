import type { ReactNode } from "react";
import { Link } from "react-router-dom";

interface LegalPageProps {
  title: string;
  lastUpdated?: string;
  children: ReactNode;
}

/**
 * Shared shell for every legal page. The copy below is a first draft: it is
 * complete and product-specific, but has not been checked by a lawyer - the
 * banner keeps that clear until it has been.
 */
export function LegalPage({ title, lastUpdated = "30 August 2026", children }: LegalPageProps) {
  return (
    <div className="legal-page">
      <p>
        <Link to="/">← Home</Link>
      </p>
      <div className="legal-draft-banner" role="note">
        Draft for legal review — this text has not yet been checked by a lawyer
        and is not legal advice.
      </div>
      <h1>{title}</h1>
      <p className="hint">Last updated: {lastUpdated}</p>
      {children}
    </div>
  );
}

/** A single policy section. */
export function LegalSection({
  heading,
  children,
}: {
  heading: string;
  children?: ReactNode;
}) {
  return (
    <section>
      <h2>{heading}</h2>
      {children}
    </section>
  );
}
