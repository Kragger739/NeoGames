import Echo from "laravel-echo";
import Pusher from "pusher-js";

import { api } from "./api";
import { getPlayerToken } from "./playerToken";

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
 */
function playerAwareAuthorizer(channel: AuthorizerChannel) {
  return {
    authorize(
      socketId: string,
      callback: (error: boolean, data: unknown) => void,
    ) {
      const playerToken = getPlayerToken();

      api
        .post(
          "/broadcasting/auth",
          { socket_id: socketId, channel_name: channel.name },
          {
            headers: playerToken ? { "X-Player-Token": playerToken } : {},
          },
        )
        .then((response) => callback(false, response.data))
        .catch((error) => callback(true, error));
    },
  };
}

/**
 * Echo must be (re)created whenever the player token changes, since the
 * authorizer captures it at construction time.
 */
export function createEcho(): Echo<"reverb"> {
  echoInstance?.disconnect();

  echoInstance = new Echo({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY as string,
    wsHost: import.meta.env.VITE_REVERB_HOST as string,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME as string) === "https",
    enabledTransports: ["ws", "wss"],
    authorizer: playerAwareAuthorizer,
  });

  return echoInstance;
}

export function getEcho(): Echo<"reverb"> {
  return echoInstance ?? createEcho();
}
