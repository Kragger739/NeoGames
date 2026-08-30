/**
 * Builds the URL a "Continue with Google/Discord" button navigates to.
 * Deliberately NOT an axios call through lib/api.ts's `api` client - the
 * provider's consent screen needs a full top-level browser navigation, not
 * an XHR, so this is meant to be used as `window.location.href = ...`.
 * Mirrors api.ts's own VITE_API_URL resolution (empty in dev -> relative
 * path proxied by Vite; an absolute URL wherever frontend/backend are on
 * different origins).
 *
 * The ?origin= param tells the backend which configured frontend origin to
 * send the browser back to after the provider redirect (it can't reliably
 * infer this from the Referer header alone - browsers/extensions don't
 * guarantee sending one) - see OAuthController::resolveOrigin().
 */
export function oauthRedirectUrl(provider: "google" | "discord"): string {
  const apiUrl = import.meta.env.VITE_API_URL as string;
  const origin = encodeURIComponent(window.location.origin);
  return `${apiUrl}/api/auth/${provider}/redirect?origin=${origin}`;
}
