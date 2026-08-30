import type { ReactNode } from "react";
import { Link } from "react-router-dom";

import { useAvConsent } from "../../hooks/useAvConsent";
import { Button } from "../ui/Button";
import { Card } from "../ui/Card";

/**
 * Shows a one-time camera/microphone notice before the DDF webcam mesh
 * starts. Once accepted (persisted per browser), renders its children.
 */
export function AvConsentGate({ children }: { children: ReactNode }) {
  const { granted, grant } = useAvConsent();

  if (granted) return <>{children}</>;

  return (
    <div className="auth-page">
      <h1>Camera &amp; microphone</h1>
      <Card className="auth-card">
        <p>
          &ldquo;Der Dümmste fliegt&rdquo; shares your camera and microphone
          live, peer-to-peer, with the other players in the room. Nothing is
          recorded or stored by us.
        </p>
        <p className="hint">
          See the <Link to="/privacy" target="_blank" rel="noreferrer">Privacy Policy</Link>.
          Your browser will also ask for permission.
        </p>
        <Button onClick={grant}>I understand — continue</Button>
      </Card>
    </div>
  );
}
