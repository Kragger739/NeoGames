---
name: NeoGames
description: A loud, warm-cream party-game hub where five candy hues each own whole regions of the screen, not just accents, and every surface commits to bounce and glossy color instead of a tool's restraint.
colors:
  bg: "#fff9f2"
  surface: "#ffffff"
  surface-sunk: "#fff2e2"
  ink: "#211c33"
  ink-soft: "#6b6180"
  ink-faint: "#a89fc0"
  coral: "#ff5c7a"
  coral-dark: "#e8355a"
  coral-wash: "#ffe4ea"
  turquoise: "#17c3b2"
  turquoise-dark: "#0e9c8e"
  turquoise-wash: "#d9f7f2"
  sunflower: "#ffc93c"
  sunflower-dark: "#e6a80f"
  sunflower-wash: "#fff2cc"
  grape: "#8b5cf6"
  grape-dark: "#6d3ce0"
  grape-wash: "#ece3fe"
  bubblegum: "#ff6fb5"
  bubblegum-wash: "#ffe3f1"
  win: "#2ed573"
  fail: "#ff4757"
  line: "#f0dfc8"
typography:
  display:
    fontFamily: "'Space Grotesk', system-ui, sans-serif"
  body:
    fontFamily: "'Plus Jakarta Sans', system-ui, sans-serif"
  h1:
    fontSize: "64px"
    fontWeight: 800
    lineHeight: 0.98
    letterSpacing: "-0.03em"
  h2:
    fontSize: "26px"
    fontWeight: 800
    lineHeight: "108%"
  body:
    fontSize: "17px"
    fontWeight: 400
    lineHeight: "150%"
rounded:
  sm: "12px"
  md: "16px"
  lg: "24px"
  pill: "999px"
spacing:
  xs: "8px"
  sm: "12px"
  md: "16px"
  lg: "24px"
  xl: "32px"
motion:
  ease-out: "cubic-bezier(0.16, 1, 0.3, 1)"
  ease-bounce: "cubic-bezier(0.34, 1.56, 0.64, 1)"
components:
  button-primary:
    backgroundColor: "{colors.coral}"
    textColor: "#ffffff"
    rounded: "{rounded.pill}"
    padding: "13px 24px"
    shadow: "0 10px 24px rgba(255,92,122,0.35)"
  card:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    padding: "20px"
    shadow: "0 10px 28px rgba(33,28,51,0.1)"
  badge:
    rounded: "{rounded.pill}"
    fontFamily: "{typography.display.fontFamily}"
    fontWeight: 800
---

# Design System: NeoGames

## Overview

**Creative North Star: "Confetti Pop"**

This is a record of the shipped reskin, not the plan for it: a warm-cream party-game hub where five saturated candy hues each own whole regions of a screen — a button, a card, a badge, a full ticket — rather than existing as a single accent sprinkled over a neutral system. The direction contract calls this out explicitly: "game night, not a dashboard — every screen commits to loud color and bounce instead of a tool's restraint." The build delivers on that thesis directly: colored glossy shadows under every elevated surface, pill and chunky radii everywhere, and a Space Grotesk display face carrying every heading and score number at full weight.

The system is one committed world, not an auto light/dark pair — `index.css` states this directly ("Confetti Pop is one committed world, not an auto light/dark pair"). There is no dark mode and none is planned; the warm cream ground is the only ground.

**Key Characteristics:**
- Five candy hues (coral, turquoise, sunflower, grape, bubblegum) each carry whole regions — buttons, card tints, badges — never a single shared accent.
- Every elevated surface gets a soft, colored, glossy shadow tinted to match its hue; flat/shadowless surfaces are rare and deliberate (form inputs, the page ground).
- Pill and chunky radii throughout (12-24px on surfaces, full pill on buttons/badges/progress bars); no sharp corners.
- Space Grotesk (700/800) for every heading, score, and badge digit; Plus Jakarta Sans for all running/body copy — a real display/body pairing, not a single stack.
- Motion is used sparingly and by role: a snappy `ease-out` for every routine hover/press, and a bouncier `ease-bounce` overshoot reserved for a small, named set of celebratory entrances (see Motion).

## Colors

### Ground
- **Bg** (`#fff9f2`): page background, the system's one ground color across every screen.
- **Surface** (`#ffffff`): the one lifted-surface fill — cards, list rows, nav pills, dropdowns, ghost buttons.
- **Surface Sunk** (`#fff2e2`): recessed fill for content that sits *within* a surface rather than on the ground — the mode-picker fieldset, `code`/counter chips, medal-rank backdrops on the results page.

### Ink
- **Ink** (`#211c33`): headings, high-emphasis text, icon strokes in illustrations.
- **Ink Soft** (`#6b6180`): the base body-copy color (set on `:root`), form labels, hints, ghost-button text.
- **Ink Faint** (`#a89fc0`): the lowest-emphasis text — round-reveal stats line, leaderboard rank numbers.

### Candy Palette
Each hue is a full-strength/dark/wash trio, and each owns *regions*, not trim:
- **Coral** (`#ff5c7a`, dark `#e8355a`, wash `#ffe4ea`): the system's primary — default button fill, focus ring, countdown timer, scoreboard score number, play-clip button, volume-slider thumb. The busiest hue in the system.
- **Turquoise** (`#17c3b2`, dark `#0e9c8e`, wash `#d9f7f2`): the secondary action hue (`btn-turquoise`, `icon-btn-turquoise`) and the player-level pill everywhere a nickname appears.
- **Sunflower** (`#ffc93c`, dark `#e6a80f`): the room-code ticket's own color, and the gold-medal gradient partner; not used as a button fill anywhere in the build — reserved for the ticket and celebratory/medal moments.
- **Grape** (`#8b5cf6`, dark `#6d3ce0`, wash `#ece3fe`): third action hue (`btn-grape`), link color (all `a` elements), scrollbar thumb, level-progress-bar gradient, level-card background.
- **Bubblegum** (`#ff6fb5`): never a button fill in the build — used only as a gradient partner (art-placeholder gradient, stage-progress fill gradient, level-bar gradient), always paired with coral or grape rather than standing alone.

### Verdicts
- **Win** (`#2ed573`, wash `#d9fbe7`): round-reveal "won" card ring + pulse, online-status dot.
- **Fail** (`#ff4757`, wash `#ffe1e3`): round-reveal "failed" card ring + shake, form-validation error text, danger button, leave-button hover, the full-screen red decay flash on a failed round.

### Named Rules
**The Region-Not-Accent Rule.** A candy hue is assigned to a whole component instance (a button's full fill, a card's full tint, a badge's full fill), never used as a thin trim line or a single sprinkled dot the way a restrained system would use an accent. If a new component needs a hue, give it the fill, the matching wash, and the matching colored shadow together — not just the color alone.

**The Verdict-Color Rule.** Win green and fail red appear in exactly one place each: the round-reveal overlay (card ring, pulse/shake, outcome line) and, for fail only, form-validation text and the leave-button's hover intent. They are not decorative candy-palette members and should never be reached for as a sixth "hue" for a non-verdict UI element.

**Every elevated surface gets a shadow tinted to its own fill**, not a neutral gray — `--shadow-coral`/`--shadow-turquoise`/`--shadow-grape` exist specifically so a colored button's shadow reads as a glow of that same color, not a generic drop shadow (see Elevation).

## Typography

**Display Font:** Space Grotesk, weights 700/800 only — loaded via Google Fonts alongside Plus Jakarta Sans.
**Body Font:** Plus Jakarta Sans, weights 400/500/600/700 (plus italic 600) — the base font for every paragraph, label, hint, and form field.
**Mono:** `ui-monospace, Consolas, monospace` — used for the room-code/counter chip only (`code`, `.counter`).

**Character:** A real display/body pairing, not a single system stack standing in for both roles. Space Grotesk's heavy weight and tight tracking carries every `h1`/`h2`/`h3`, badge digit, scoreboard score, and level label; Plus Jakarta Sans carries everything meant to be read at length. The pairing itself is the personality — headings are loud and geometric, body copy stays warm and legible underneath.

### Hierarchy
- **H1** (800, 64px desktop / 40px under 1024px, line-height 0.98, letter-spacing -0.03em, `text-wrap: balance`): page titles — dashboard greeting, "Room ABC123", "New room." The game-play screen's own `h1` drops to a fixed 36px regardless of breakpoint, sized for its narrower two-column main slot.
- **H2** (800, 26px desktop / 22px under 1024px, line-height 108%): section headers within a page — "Players," "Scores," the scoreboard-panel heading.
- **Body** (400, 17px desktop / 16px under 1024px, line-height 150%): the page base size and all running copy.
- **Labels/Small text** (600-800 weight, 11-15px): form labels (14px/600), hints and form-errors (14px/600), the player-level pill (11px/800), badges (12px/800, display face), the mode-picker legend (13px/800, uppercase, tracked).

### Named Rules
**The Display-Carries-Numbers Rule.** Anywhere a number is the point of the UI — scoreboard scores, badge digits (rank medals, player-level pill), the results-page rank counters, the profile level label — it renders in Space Grotesk 800, not the body face, even at small sizes. This is what makes scores and ranks read as the "loud" data points against the quieter Plus Jakarta Sans labels around them.

**No third font family.** Every weight of both hierarchies is carried by exactly two families (Space Grotesk for display roles, Plus Jakarta Sans for body/label roles) plus the single monospace exception for the room-code chip. Do not introduce a third face for a new component.

## Layout

A single centered column at every page: `.auth-page`/`.home-page`/`.songle-page`/`.new-room-page`/`.join-page`/`.lobby-page`/`.results-page`/`.profile-page`/`.friends-page` all cap at 640px, centered, with generous 48px/80px top/bottom padding. The live game-play screen is the one page that widens, to 920px, to hold its two-column layout. The Home page's game-picker grid (see Components → Game Tiles) fits inside this same 640px cap via a `minmax`/auto-fill grid — the same technique the mode-picker already uses — rather than joining game-play as a widened exception.

The game-play screen is the system's only page with real responsive restructuring: a flexible ~380px main column (clip player, guess box, reveal) sits beside a 220px-fixed scoreboard panel side by side above 720px width, and stacks (`column-reverse` — scoreboard first, then main) below it. On mobile, the scoreboard itself collapses from a vertical list to a horizontal scrolling strip so it stays compact near the guess input ("controller mode," per the in-code comment). Every other page stays single-column and static across breakpoints.

Spacing has a loose but consistent rhythm rather than a strict 4/8 grid: 8px for tight internal gaps (icon-to-label, badge padding), 12-16px for form-field gaps and card padding, 24px for section margins and page-level gaps, 32px for the game-play layout's column gap. No page or panel exceeds its own shell width — page shells are load-bearing for the "bordered game-night card" read even though (unlike a bordered-shell system) there's no visible outer border here; the containment comes from centering and max-width alone.

## Elevation & Depth

Every lifted surface gets a shadow, and the shadow is colored to match the surface's own fill — this is the load-bearing elevation idea in Confetti Pop, the opposite of a flat-and-outlined system. A card, a filled button, an icon button, and a list row are each visually "sitting above" the cream ground via a soft, wide, low-opacity shadow rather than a border or a fill-contrast trick.

### Shadow Vocabulary
- **Shadow SM** (`0 4px 12px rgba(33,28,51,0.08)`): the resting elevation for neutral (non-hued) surfaces — nav pills, list rows, ghost buttons, icon buttons in their ghost variant, the avatar-preview-small frame.
- **Shadow Card** (`0 10px 28px rgba(33,28,51,0.1)`): the `.card` primitive's resting shadow, and the hover target for nav pills/ghost buttons/mode-options stepping up from Shadow SM.
- **Shadow Coral / Turquoise / Grape** (`0 10px 24px rgba(<hue>,0.32-0.35)`): the resting shadow for any surface filled with that hue — primary/turquoise/grape buttons, matching icon-button variants, the room ticket (sunflower-tinted inline), the volume-slider thumb.
- **Shadow Float** (`0 20px 48px rgba(33,28,51,0.18)`): reserved for genuinely floating, overlapping-the-page elements — the guess-autocomplete dropdown, the round-reveal card, the room-invite toast.

### Named Rules
**The Hue-Matched Shadow Rule.** A hued surface's shadow uses that hue's own shadow token, never the neutral Shadow SM/Card. If a new component introduces a sixth candy-adjacent fill, it needs its own `--shadow-<hue>` token rather than borrowing coral's.

**The Float Tier Is Reserved.** Shadow Float only applies to elements that overlap other in-flow content (dropdown, modal card, toast). A panel that is part of normal page flow — even a prominent one like the level-card or a mode-option — stays on Shadow SM/Card, never Float.

## Shapes

Radius scales by role, and the system leans pill/chunky rather than gently-rounded: 12px (`--radius-sm`) on inputs, suggestion-art thumbnails, and small badges-in-context; 16px (`--radius-md`) on buttons' base radius (before the pill override), list rows, mode-options, form inputs; 24px (`--radius-lg`) on cards, the round-reveal card, the level-card, large avatar frames; full pill (`--radius-pill`) on every `.btn`, every badge, nav pills, the play-clip button, progress-bar tracks/fills, and volume-slider geometry. No sharp corners exist anywhere in the build.

## Motion

Two eases, used for two different jobs — this distinction is load-bearing and should not be collapsed:

- **`--ease-out`** (`cubic-bezier(0.16, 1, 0.3, 1)`) is the system's routine-motion ease: every button/nav-pill/card/mode-option hover-lift, every press-down, the level-bar fill transition. It is snappy and settles without overshoot.
- **`--ease-bounce`** (`cubic-bezier(0.34, 1.56, 0.64, 1)`) is reserved for celebratory *entrances* only, never for hover or press feedback. In the shipped build it drives exactly the `reveal-pop` keyframe, which is applied to two elements: the round-reveal card's pop-in, and the room-invite toast's pop-in. Both are "something new just appeared to be celebrated/noticed" moments, not routine interaction feedback.

### Named Rules
**The Bounce-Is-For-Arrivals-Only Rule.** `--ease-bounce` may only be attached to an entrance animation for a genuinely new, attention-worthy element appearing on screen (a modal/overlay card, a toast). It must never be applied to `:hover`/`:active` states, form-field focus, or any transition that fires on every routine interaction — those stay on `--ease-out`. A future component that wants "energy" on hover should reach for the existing translateY+scale lift pattern (see Buttons), not for bounce easing.

**Sparing use, not everywhere.** Outside the reveal-pop entrances, the dashboard-hero bob-rotate loop (`hero-bob`, 3.5s ease-in-out, disabled under `prefers-reduced-motion`), the round-reveal win-pulse/fail-shake, and the stage-progress fill transition, the system does not animate. Static surfaces should stay static; a new animation needs a specific celebratory or state-communicating job, not decoration.

## Components

### Buttons
- **Shape:** full pill radius, always, via the shared `.btn` class (13px/24px padding, 18px/36px for `.btn-lg`).
- **Primary** (`btn-primary`, default): solid Coral fill, white text, Shadow Coral. This is the system's default/expected button.
- **Turquoise / Grape** (`btn-turquoise`/`btn-grape`): equal-weight alternates to primary, same shape/shadow pattern in their own hue — used to differentiate a secondary primary action (e.g. a different room action) rather than to indicate lower importance.
- **Ghost** (`btn-ghost`): the only outlined-at-rest-adjacent option — Surface fill (not transparent), Ink Soft text, Shadow SM. Used next to a primary/hued action for a non-primary, non-destructive choice (copy-invite, leave, dashboard logout), never as a replacement for a primary action.
- **Danger** (`btn-danger`): solid Fail fill, white text, red-tinted shadow — reserved for destructive actions.
- **Hover:** every hued/ghost button lifts (`translateY(-2px) scale(1.02)`) and deepens its shadow on `:hover:not(:disabled)`, via `--ease-out`. Press (`:active`) settles to `translateY(0) scale(0.98)`.
- **Focus:** every input/select/button shares one focus treatment — Coral border + `0 0 0 4px` Coral Wash ring, no per-component variation.
- **Disabled:** 0.45 opacity, `cursor: not-allowed`, shadow removed.
- All purposeful actions route through the `Button` primitive (`components/ui/Button.tsx`); a raw `<button className="...">` is treated in-repo as a lapse, reserved only for third-party/settings-form controls that need the browser default.

### Icon Buttons
- 40px circle, `.icon-btn` + a hue variant (`ghost`/`coral`/`turquoise`). Ghost matches the neutral button treatment (Surface + Shadow SM); hued variants match their button counterparts. Icons are Lucide SVG line icons (18px, 2.25 stroke), always paired with an `aria-label`/`title` since the button carries no visible text.

### Cards
- **Base** (`.card`): Surface fill, 24px radius, Shadow Card, 20px padding — the one lifted-surface container.
- **Tint** (`card-tint-<hue>`): washes the fill toward one candy hue's wash token for selectable/categorized cards.
- **Interactive** (`card-interactive`): adds a hover lift (`translateY(-3px)`) and steps the shadow up to Shadow Float, via `--ease-out`.

### Badges
- Pill radius, Space Grotesk 800, 12px, tight (22px-tall) footprint. Hue variants (`coral`/`turquoise`/`sunflower`/`grape`) are solid fills; `gold`/`silver`/`bronze` are diagonal metallic gradients reserved for rank/medal contexts (results podium, avatar frames).

### Game Tiles
- The Home page's game picker (`.game-grid`) is a `minmax(220px,1fr)` auto-fill grid of `.game-tile` elements, each carrying the same base `card` classes as the Cards primitive above. A playable tile adds `card-tint-<hue>` (Songle uses turquoise, deliberately distinct from the page's coral buttons/badges) and is itself the navigation link (`<Link>` wearing the card classes, not a card nested inside a link). Its hover state is bespoke rather than `card-interactive`: a `translateY(-3px)` lift paired with that tile's own hue-matched shadow token (e.g. `--shadow-turquoise`) — `card-interactive`'s Shadow Float hover does not apply here, since an in-flow grid tile isn't a floating/overlapping element (see Elevation → Float Tier Is Reserved). A locked "Coming soon" tile (not yet a built game) gets no `tint`, a Lucide icon in place of an illustration, and the same dimmed/no-lift treatment as `.mode-option-locked` — a second, named instance of that pattern, not a one-off.

### Room Ticket
- The lobby's room code renders as a torn-edge event ticket (`.room-ticket`): Sunflower fill with a radial-gradient perforation pattern simulating a ticket stub edge, a slight `-1.5deg` rotation, and its own sunflower-tinted shadow. This is a one-off, named treatment — not a generalized "torn edge" utility — reserved for the single piece of information every page exists to help players find and share.

### Round Reveal
- A fixed, full-viewport scrim (`rgba(33,28,51,0.55)`, blurred) centers a 380px card (Shadow Float, 24px radius) that pops in via `reveal-pop`/`--ease-bounce`. Won/failed state adds a 4px hue ring (Win/Fail) plus a state-specific animation: a two-cycle green pulse for a win, a single shake for a fail. A failed round also flashes the overlay scrim itself from red to neutral once (`red-screen-glow`, single decay, explicitly not a repeating strobe — the in-code comment flags this as a deliberate photosensitivity consideration). Album art renders at 220px, 24px radius, Shadow Card.

### Lists (players / scoreboard / miss feed)
- Surface fill, Shadow SM, 16px radius, space-between flex, 14px/18px padding. Scoreboard rows render their trailing score in Space Grotesk 800 Coral — the one place the primary hue appears inside an otherwise neutral row. Results-page ranked lists add numbered/medaled `::before` badges (gold/silver/bronze gradients for 1st-3rd) and a sunflower ring on the first-place row.

### Inputs
- Surface fill, 2px `--line` border, 12px radius, 13px/16px padding. Shared Coral focus ring (see Buttons). No distinct invalid/error state on the field itself; validation errors surface as a separate `.form-error` line in Fail red below the form.

### Illustrations
Hand-authored inline SVG — `PartyNote` (Songle's own page hero, full-size and dancing; also reused at small icon scale for Songle's tile on the Home page's game grid), `Podium` (results hero), `ConfettiBurst`/`WhiffedIt` (round-reveal outcome badges), `EmptyFriends` (empty-state art). These are original vector illustrations, not generated raster images or stock art — no AI image-generation tool is available in this build environment, so illustration coverage is scoped to what could be hand-authored as SVG, and every illustration is built from the same candy-palette hex values as the rest of the system rather than an independent palette. Not-yet-built games (locked tiles on Home) use a plain Lucide icon instead of a new illustration — Lucide is already the system's sanctioned functional-icon language, so this isn't a second illustration style. `GoogleMark`/`DiscordMark` (the "Continue with Google/Discord" auth buttons) are the one deliberate exception to the shared candy palette — they reproduce each provider's real brand mark and colors, since a sign-in button needs to be instantly recognizable and providers' own brand guidelines expect their real mark, not a reskinned one.

### Sound
Short synthesized tones (`lib/sounds.ts`) via raw Web Audio oscillators + gain envelopes — `win`/`fail`/`correct`/`tick`/`join`, each a small chord/arpeggio rather than a single beep. Not licensed audio clips: no audio-clip source was available in this build environment, so sound is a real, intentional synthesis-based substitute rather than a placeholder. Muted by default off, toggle persisted to `localStorage`.

## Do's and Don'ts

### Do:
- **Do** give every hued fill (button, icon-button, card tint, ticket) its own matching wash and matching colored shadow token together — a hue without its shadow reads unfinished in this system.
- **Do** carry every number that matters (scores, ranks, badge digits, level) in Space Grotesk 800, even at small sizes.
- **Do** reserve `--ease-bounce` for entrance animations of genuinely new/celebratory elements (modal card, toast); use `--ease-out` for all routine hover/press/focus motion.
- **Do** keep the page shell centered and capped (640px standard, 920px only for the two-column game-play screen).

### Don't:
- **Don't** apply `--ease-bounce` to a hover or press state — that distinction (bounce = arrival, ease-out = routine feedback) is a deliberate, named rule, not an oversight to "fix" toward consistency.
- **Don't** treat Win green / Fail red as a sixth candy hue available for decoration — they are verdict colors, confined to the round-reveal overlay and (fail only) form-validation/destructive-action contexts.
- **Don't** add a second illustration style (icon-font glyphs, stock art, or a differently-drawn line weight) — all illustration in this system is hand-authored SVG sharing the same palette and the same rounded, high-contrast-face character as `PartyNote`/`Podium`.
- **Don't** generalize the room-ticket's torn-edge/rotation treatment into a reusable "ticket" component elsewhere — it is a one-off treatment for the single most-shared piece of information in the app, not a pattern the system has committed to repeating.
- **Don't** introduce a third font family; Space Grotesk (display) and Plus Jakarta Sans (body) plus the single monospace room-code exception are the complete set.
