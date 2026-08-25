# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Two distinct user types:

- **Hosts** — register/log in with an account, create a game room, and run a game night. Hosts can now also join their own room's guessing pool instead of only moderating.
- **Players** — join anonymously via a shared invite link and a nickname, no account required. Identity is session-only (closing the tab means they can't rejoin as themselves — a deliberate trade-off).

This is a public product: hosts are not limited to the builder's own friend circle. Anyone who signs up can create rooms and invite their own friend groups to play, remotely or in the same room, from any device with a browser.

## Product Purpose

A real-time, multiplayer party-game platform. The first game is "Guess the Song": a Kahoot/JackBox-style music-guessing game where a host starts a round, an audio snippet plays, and players race to guess the title/artist before the snippet escalates in length or time runs out. The platform is architected to support additional party games later (rooms are already namespaced by a `game` field). Success means a group of friends can start a full game night in minutes with zero host prep.

## Positioning

Zero-setup, always-fresh songs. Unlike Kahoot, JackBox, or SongPop — which rely on a host-curated playlist or a fixed licensed catalog — this platform discovers songs automatically per game via the iTunes Search API (real preview clips, no manual upload) and derives difficulty from Last.fm listener-count popularity. The song pool also rotates itself (least-recently-used songs are favored) so repeat games don't feel like the same setlist. Hosts never build or maintain a song library.

## Operating Context

- Hosts register/log in (email+password), create a room (configurable songs-per-tier and guess-timeout), and get a shareable room code / invite link.
- Players join anonymously via the invite link plus a nickname.
- Gameplay is real-time over WebSockets: every participant sees the same round state, snippet length, and live scoreboard simultaneously.
- 5 escalating difficulty tiers per game (easy → intermediate → medium → hard → extreme). Per-round snippet length escalates on a wrong guess or timeout (0.1s → 0.5s → 1s → 5s → 15s) rather than being revealed upfront.
- The host can play alongside their friends in their own room, not only operate it.

## Capabilities and Constraints

- Host accounts via Laravel Sanctum; anonymous players via a separate per-connection token auth guard (no shared session with the host).
- Audio playback is intentionally locked down: no native scrubbing, downloading, or replay past the current round's authorized snippet length.
- Search-as-you-type guess autocomplete queries iTunes live (not the room's own discovered pool), so suggestions never leak the answer.
- Player-adjustable overall volume, independent of the locked snippet duration.
- Backend: Laravel 13 (PHP), Sanctum, Reverb (self-hosted WebSocket broadcasting).
- Frontend: React 19 + TypeScript + Vite SPA, Zustand, React Router.
- Song audio: Apple iTunes Search API (public preview clips). Popularity/difficulty: Last.fm `track.getInfo` listener counts, log-scaled with a floor so even the hardest tier stays somewhat recognizable rather than truly obscure.
- Undecided: monetization model, production hosting/deployment target, and whether public host signup will be fully open or invite-gated.

## Brand Commitments

None confirmed. "NeoGames" is currently only the working repo/package name — not a confirmed public product name. Do not treat it as a locked brand identity.

## Evidence on Hand

No real testimonials, screenshots, press, or marketing copy exist yet. No logo or brand asset files exist in the repo. Do not fabricate any of these.

## Product Principles

1. Zero host prep — never require curating, uploading, or maintaining a song list before playing.
2. Fairness through calibrated obscurity — even "extreme" difficulty stays guessable by someone; never true trivia-obscurity.
3. Snippet integrity — a player can never hear more of a song than the round has currently authorized, regardless of client-side tricks.
4. Everyone can play — the host is a participant by default, not just an operator.
5. Low friction to join — playing requires nothing but a nickname and a link; no install, no account.

## Accessibility & Inclusion

No product-specific accessibility requirement has been established yet.
