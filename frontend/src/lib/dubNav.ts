import type { DubGameState } from "./dubTypes";

/** Which /dub-rooms/{code}/* route a given game state belongs on. */
export function dubPathForState(code: string, state: DubGameState): string {
  switch (state) {
    case "recording":
    case "assembling":
      return `/dub-rooms/${code}/record`;
    case "watch":
    case "rating":
    case "round_complete":
      return `/dub-rooms/${code}/watch`;
    case "finished":
      return `/dub-rooms/${code}/results`;
    case "lobby":
    case "role_claim":
    default:
      return `/dub-rooms/${code}/lobby`;
  }
}
