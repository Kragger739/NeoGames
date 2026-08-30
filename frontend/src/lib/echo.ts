import Echo from "laravel-echo";
import Pusher from "pusher-js";

import { api } from "./api";

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

window.Pusher = Pusher;

let echoInstance: Echo<"reverb"> | null = null;

interface AuthorizerChannel {
  name: string;
}

/**
 * Custom authorizer so /broadcasting/auth goes through the shared axios
 * client: hosts authenticate via the Sanctum session cookie, players via the
 * X-Player-Token header. Reverb's default authorizer doesn't know about our
 * player guard, so we can't use it as-is.
 *
 * The player token is only attached for room.{code} channels - never for
 * the account-identity channels (online-users, App.Models.User.{id}).
 * /broadcasting/auth resolves via "auth:player,sanctum" (player guard tried
 * first - see bootstrap/app.php), so a logged-in host who's also currently
 * seated as a player in their own room (every host is, automatically) would
 * otherwise authenticate those channels as the RoomPlayer instead of the
 * User, which routes/channels.php explicitly rejects for both - silently
 * breaking presence (online friends never populate) and the room-invite
 * notification channel for the entire time the host is inside a room.
 */
function playerAwareAuthorizer(channel: AuthorizerChannel) {
  return {
    authorize(
      socketId: string,
      callback: (error: boolean, data: unknown) => void,
    ) {
      const isRoomChannel = /^(presence|private)-room\./.test(channel.name);

      // api's own request interceptor would otherwise attach
      // X-Player-Token to this call unconditionally whenever one exists in
      // sessionStorage - skipPlayerToken overrides that for every channel
      // except room.{code} (see this function's docblock above).
      api
        .post(
          "/broadcasting/auth",
          { socket_id: socketId, channel_name: channel.name },
          { skipPlayerToken: !isRoomChannel },
        )
        .then((response) => callback(false, response.data))
        .catch((error) => callback(true, error));
    },
  };
}

/**
 * Reverb connection target. When VITE_REVERB_HOST is left blank (the
 * default), it's derived from the origin actually serving the page and the
 * websocket rides the Vite dev server's `/app` proxy through to local
 * Reverb (see vite.config.ts). That's what keeps a Cloudflare quick tunnel
 * working without re-pinning a hostname here every time the tunnel's random
 * *.trycloudflare.com name changes - and localhost dev works the same way.
 * Set the three VITE_REVERB_* vars explicitly only to point straight at a
 * real, separately-hosted Reverb server (e.g. in production).
 */
function reverbConnection(): { host: string; port: number; forceTLS: boolean } {
  const envHost = (import.meta.env.VITE_REVERB_HOST as string | undefined)?.trim();

  if (envHost) {
    return {
      host: envHost,
      port: Number(import.meta.env.VITE_REVERB_PORT) || 443,
      forceTLS: (import.meta.env.VITE_REVERB_SCHEME as string) === "https",
    };
  }

  const isHttps = window.location.protocol === "https:";

  return {
    host: window.location.hostname,
    port: Number(window.location.port) || (isHttps ? 443 : 80),
    forceTLS: isHttps,
  };
}

/**
 * Echo must be (re)created whenever the player token changes, since the
 * authorizer captures it at construction time.
 */
export function createEcho(): Echo<"reverb"> {
  echoInstance?.disconnect();

  const connection = reverbConnection();

  echoInstance = new Echo({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY as string,
    wsHost: connection.host,
    wsPort: connection.port,
    wssPort: connection.port,
    forceTLS: connection.forceTLS,
    enabledTransports: ["ws", "wss"],
    // laravel-echo's runtime authorizer contract (boolean-first callback)
    // predates pusher-js's bundled types (Error-first). The runtime call in
    // playerAwareAuthorizer is correct for laravel-echo; only the type
    // declarations disagree. Drop this if the upstream types ever reconcile.
    // @ts-expect-error - upstream laravel-echo / pusher-js type drift
    authorizer: playerAwareAuthorizer,
  });

  return echoInstance;
}

export function getEcho(): Echo<"reverb"> {
  return echoInstance ?? createEcho();
}
