---
name: NeoGames
description: A quiet, dark-first real-time party-game lobby with one purple accent that only shows up when it matters.
colors:
  late-night-violet: "#c084fc"
  late-night-violet-wash: "rgba(192, 132, 252, 0.15)"
  late-night-violet-line: "rgba(192, 132, 252, 0.5)"
  lobby-floor: "#16171d"
  marquee-ink: "#f3f4f6"
  hallway-fog: "#9ca3af"
  curtain-line: "#2e303a"
  booth-seat: "#1f2028"
typography:
  heading:
    fontFamily: "system-ui, 'Segoe UI', Roboto, sans-serif"
    fontSize: "56px"
    fontWeight: 500
    lineHeight: 1
    letterSpacing: "-1.68px"
  title:
    fontFamily: "system-ui, 'Segoe UI', Roboto, sans-serif"
    fontSize: "24px"
    fontWeight: 500
    lineHeight: "118%"
    letterSpacing: "-0.24px"
  body:
    fontFamily: "system-ui, 'Segoe UI', Roboto, sans-serif"
    fontSize: "18px"
    fontWeight: 400
    lineHeight: "145%"
    letterSpacing: "0.18px"
  label:
    fontFamily: "system-ui, 'Segoe UI', Roboto, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: "normal"
    letterSpacing: "normal"
rounded:
  sm: "6px"
  md: "8px"
  lg: "10px"
spacing:
  xs: "6px"
  sm: "8px"
  md: "14px"
  lg: "24px"
  xl: "40px"
components:
  button-primary:
    backgroundColor: "{colors.late-night-violet-wash}"
    textColor: "{colors.late-night-violet}"
    rounded: "{rounded.md}"
    padding: "10px 18px"
  button-primary-hover:
    backgroundColor: "{colors.late-night-violet}"
    textColor: "{colors.lobby-floor}"
  button-secondary:
    backgroundColor: "transparent"
    textColor: "{colors.hallway-fog}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
  button-secondary-hover:
    backgroundColor: "{colors.booth-seat}"
    textColor: "{colors.marquee-ink}"
  input:
    backgroundColor: "{colors.booth-seat}"
    textColor: "{colors.marquee-ink}"
    rounded: "{rounded.md}"
    padding: "10px 12px"
  list-row:
    backgroundColor: "{colors.booth-seat}"
    textColor: "{colors.marquee-ink}"
    rounded: "{rounded.md}"
    padding: "10px 14px"
---

# Design System: NeoGames

## Overview

**Creative North Star: "The Late-Night Lobby"**

Nothing here has been art-directed as a brand yet — this is an honest record of what the running app actually looks like: a system-font, single-accent, flat-outlined interface that auto-switches between a bright and a dark theme with the OS. What it does have, consistently, is restraint: one purple accent, used sparingly, against a near-black room at night. That's the read worth naming and preserving rather than losing to a generic redesign later.

The Late-Night Lobby is the feel of a group of friends gathered in a dim room around one shared screen — the lights are low, the only glow comes from the one thing that matters (the accent), and everything else stays out of the way. It is quiet and functional, not decorative: no gradients, no illustration, no competing colors. The system explicitly avoids reading like a corporate SaaS dashboard — no card-heavy density, no multi-color status-chip language, no enterprise blue.

**Key Characteristics:**
- One accent color, used deliberately, not sprinkled
- Flat and outlined at rest; solid fills and shadows are reserved for hover/focus/overlay states
- Auto light/dark via OS preference, with dark as the primary, intended reading
- System-font typography — no custom display face, nothing overwrought
- A bordered, centered single column (never full-bleed), even at desktop width

## Colors

A near-monochrome neutral scale (paper-white to near-black across modes) carrying one accent hue. Every screen uses at most one non-neutral color at a time.

### Primary
- **Late-Night Violet** (`#c084fc` dark / `#aa3bff` light): the system's only accent. Used for links, focus rings, the guess-timer countdown, primary-button text/border, and the trailing score number in every scoreboard row. Two supporting tones ride alongside it rather than existing as separate hues:
  - **Violet Wash** (`rgba(192, 132, 252, 0.15)` dark / `rgba(170, 59, 255, 0.1)` light): the primary button's resting fill.
  - **Violet Line** (`rgba(192, 132, 252, 0.5)` dark / `rgba(170, 59, 255, 0.5)` light): the primary button's resting border, and the border on hover states that want to hint "this is interactive" before committing to the full accent.

### Neutral
- **Lobby Floor** (`#16171d` dark / `#fff` light): page background.
- **Marquee Ink** (`#f3f4f6` dark / `#08060d` light): headings and other high-emphasis text (`h1`, `h2`, input values, list-row primary text).
- **Hallway Fog** (`#9ca3af` dark / `#6b6375` light): body copy, hints, secondary/ghost button text.
- **Curtain Line** (`#2e303a` dark / `#e5e4e7` light): every hairline border — inputs, buttons, list rows, the page shell's own left/right edge.
- **Booth Seat** (`#1f2028` dark / `#f4f3ec` light): the one surface-fill color. Anything that needs to read as "sitting slightly above the floor" without a shadow uses this: inputs, nav pills, list rows (players/scoreboard/miss-feed), the autocomplete dropdown.

### Status
- **Win Green** (`#4ade80` dark / `#16a34a` light, wash `rgba(74, 222, 128, 0.15)` dark / `rgba(22, 163, 74, 0.12)` light, glow `rgba(74, 222, 128, 0.65)` dark / `rgba(34, 197, 94, 0.55)` light): reserved solely for the round-reveal overlay's "won" state — border, pulse glow, and the winner line.
- **Lose Red** (`#dc2626` light / same in dark, glow `rgba(239, 68, 68, 0.55)`): reserved solely for the round-reveal overlay's "failed" (nobody guessed it) state — border, standing glow, and the outcome line. Shares the confirmed-exception status of the pre-existing `#ef4444` form-error red (see Don't list) rather than being a third freestanding hue.

### Named Rules
**The One Accent Rule.** Late-Night Violet is the only non-neutral hue anywhere in the system for anything other than a pass/fail verdict. A second decorative accent color on a future screen still breaks the Lobby's identity — reach for weight, size, or the Violet Wash/Line tones before reaching for a new hue. Win Green and Lose Red are the confirmed exception: every round's outcome is exactly one of won/failed, so the reveal overlay is the one place the system commits to a real verdict color instead of staying neutral.

**The Dark-First Rule.** Dark is the primary, designed-for reading (`prefers-color-scheme: dark`); light is a courtesy fallback, not an equally-weighted alternate. When only one mode's values can be shown (mockups, marketing stills), show dark.

## Typography

**Body/Heading Font:** system-ui, 'Segoe UI', Roboto, sans-serif (no custom or display face loaded)
**Mono:** ui-monospace, Consolas, monospace — declared as a token (`--mono`) but not currently used by any rendered screen; do not extend it into a new component without a reason, since nothing today establishes its voice.

**Character:** A single system-font family carrying every weight of the hierarchy itself; there is no display/body pairing to speak of. Whatever personality the type has comes from size, weight (500 for headings, 400 for body), and negative tracking on headings, not from typeface choice.

### Hierarchy
- **Heading** (500, 56px desktop / 36px under 1024px, line-height 1, letter-spacing -1.68px): page titles (`h1`) — "Room ABC123", "New room", "Welcome, {name}". Drops to a fixed 32px on the game-play screen's narrower main column only, where the room code shares space with the two-column layout.
- **Title** (500, 24px desktop / 20px under 1024px, line-height 118%, letter-spacing -0.24px): section headers within a page (`h2`) — "Players (3)", "Scores".
- **Body** (400, 18px desktop / 16px under 1024px, line-height 145%, letter-spacing 0.18px): all running copy and the page's base font size. The play-clip button and round-reveal panel intentionally step up to 16px regardless of breakpoint, since both are always-visible, high-frequency-read controls.
- **Label** (400, 13-16px, normal spacing): the system's smallest text has more range than a single value — 13px for the profile edit button, 14-15px for hints/form-errors/list-row text/the stage-info countdown line. Treat 13-16px as the Label role's working range, not a single fixed size.

### Named Rules
**The No-Display-Face Rule.** Every weight of the hierarchy is carried by the same system-font stack. Do not introduce a second font family for "emphasis" — use size, weight, or the accent color instead.

## Layout

A single centered column, never full-bleed, even at the widest viewport: the root shell caps at 1126px with a 1px `Curtain Line` border down each inline edge, so the app always reads as a bordered card floating on the OS/browser chrome rather than a page that fills the screen. Individual pages nest a narrower shell inside that: 560px max-width for every form-driven page (auth, new room, join, lobby, results), widened to 760px only for the live game-play screen, which needs room for its two-column layout.

The game-play screen is the one page with real responsive restructuring: a 380px-flex main column (clip player, guess box, reveal) sits beside a 200px-fixed scoreboard panel side by side above 720px, and stacks vertically (scoreboard below main) under it. Every other page stays single-column at all sizes.

Spacing has no rigid 4px/8px grid, but a consistent rhythm: 6px for the tightest internal gaps (form-label-to-input, small pill padding), 8px for list-item gaps and secondary-button padding, 14px for form-field gaps and list-row padding, 24px for margins between page sections, 40px for the page shell's own top/bottom padding.

## Elevation & Depth

Flat by default. Depth is not ambient — there is exactly one `box-shadow` in the whole system (`0 10px 15px -3px rgba(0,0,0,0.1|0.4), 0 4px 6px -2px rgba(0,0,0,0.05|0.25)`), and it exists solely to lift the guess-autocomplete dropdown above the page content it's floating over. Every other surface — buttons, inputs, list rows, panels — stays flat, separated from its neighbor by a 1px `Curtain Line` border or a `Booth Seat` fill, never a shadow.

### Shadow Vocabulary
- **Overlay Lift** (`box-shadow: rgba(0,0,0,0.1) 0 10px 15px -3px, rgba(0,0,0,0.05) 0 4px 6px -2px` in light; darker alphas in dark mode): the only sanctioned use is a floating panel that overlaps other content (currently the guess-suggestions dropdown and the round-reveal card/art).
- **Overlay Scrim** (`rgba(0, 0, 0, 0.55)`, same value both modes): full-viewport backdrop dimming behind the round-reveal overlay, the system's one true modal. Neutral black rather than a tinted color, so it reads as "everything behind this is paused" without competing with the win/lose verdict color inside the card.

### Named Rules
**The Shadow-Means-Floating Rule.** A shadow is a signal that something is temporarily overlapping the page, not a decoration for a resting surface. If a new panel is part of the page's normal flow, it gets a border and/or `Booth Seat` fill instead — never a shadow.

## Shapes

Three radius steps, applied by role rather than size: 6px on the smallest interactive controls (the suggestion label inside the autocomplete dropdown), 8px on everything a person directly acts on (buttons, inputs, nav pills, list rows), 10px on floating/emphasis panels (the autocomplete dropdown, the round-reveal callout). No pill/fully-rounded shapes and no sharp corners anywhere — the system sits entirely in the "gently rounded" register.

## Components

### Buttons
- **Shape:** 8px radius, always.
- **Primary** (default `<button>`): resting state is a Violet Wash fill with Late-Night Violet text and a Violet Line border — a tinted "ghost" rather than a solid button. On hover it commits fully: solid Late-Night Violet background with Lobby Floor (background-color) text. Disabled drops to 0.5 opacity with `cursor: not-allowed`.
- **Secondary / Ghost** (dashboard logout, copy-invite-link): transparent background, Curtain Line border, Hallway Fog text. Hover fills with Booth Seat and brightens text to Marquee Ink. Used for non-primary, non-destructive actions sitting next to a primary action.
- **Focus:** every interactive control (button, input, select) shares one focus treatment — a 2px Late-Night Violet outline, 2px offset. No per-component variation.

### Cards / Panels
- **Round Reveal:** a full-viewport Overlay Scrim (see Elevation) centers a 360px card — Lobby Floor background (not a tint), Overlay Lift shadow, 10px radius, 32px/28px padding, popping in with a `reveal-pop` scale/fade. The card's border and glow commit to Win Green (won) or Lose Red (failed, with a brief `red-shake`) — the one place the system uses a verdict color instead of Curtain Line neutral. Holds the answer's album art (10px radius, Overlay Lift shadow), title, artist, and the outcome line, and replays the full-length snippet once before the next round starts.
- **Autocomplete Dropdown:** Booth Seat background, Curtain Line border, 10px radius, Overlay Lift shadow — the other shadowed surface in the system (see Elevation).

### List Rows (players / scoreboard / miss feed)
- **Style:** Booth Seat fill, Curtain Line border, 8px radius, space-between flex layout, 14px padding.
- **Scoreboard variant:** the trailing score is bold and Late-Night Violet — the only list row where the accent appears inside a neutral row, marking it as the number that matters.

### Inputs / Fields
- **Style:** Booth Seat fill, Curtain Line border, 8px radius, Marquee Ink text, 10-12px padding.
- **Focus:** the shared 2px Late-Night Violet outline (see Buttons).
- No distinct error/invalid visual state on the input itself — validation errors surface as a separate `.form-error` line below the form, not a red border on the field.

### Navigation
- **Dashboard nav links:** styled as pills, not underlined text links — Booth Seat fill, Curtain Line border, 8px radius, no-underline. Hover shifts the border to Violet Line rather than filling the background, distinguishing a nav pill's hover from a button's hover.

## Do's and Don'ts

### Do:
- **Do** keep every button and input flat and outlined at rest; let hover/focus be the only moment a solid fill or the full accent color appears.
- **Do** use Booth Seat as the one surface-fill color for anything that needs to separate from Lobby Floor without a shadow (rows, dropdowns, panels, inputs).
- **Do** design for dark mode first; treat the light-mode values as the fallback, not the primary target.
- **Do** reserve Late-Night Violet for the thing that actually matters on a given screen (a link, a score, a countdown, a focus ring) — its rarity is what makes it read as intentional.

### Don't:
- **Don't** introduce a second decorative accent hue. Status/severity needs should stay off-palette-neutral where possible; the confirmed exceptions are the plain `#ef4444` form-validation error text, and Win Green / Lose Red on the round-reveal overlay (see Status) — both are pass/fail verdicts, not decoration.
- **Don't** add a shadow to a resting, in-flow surface — shadows are reserved for things floating over other content (see The Shadow-Means-Floating Rule). The round-reveal overlay is the one full-viewport exception (Overlay Scrim), since it's a true modal, not in-flow page content.
- **Don't** widen any page past its shell (560px for form pages, 760px for game-play, 1126px for the outer app border) — the bordered, centered-column read is load-bearing for the Late-Night Lobby identity, not an incidental default.
- **Don't** expose native `<audio>` playback controls (scrubber, download, replay) anywhere near the game-play screen — this is a confirmed product constraint (see PRODUCT.md), and a native control bar would also visually contradict the "quiet, intentional controls only" character of the rest of the system.
