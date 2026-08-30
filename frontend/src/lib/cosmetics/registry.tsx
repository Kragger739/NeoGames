// The cosmetic registry: slot metadata + the key -> SVG component map. Adding
// a cosmetic later is one new SVG + one entry here (and a catalogue row on the
// backend) - nothing else changes. An unknown key renders nothing, so the
// frontend never has to ship in lockstep with the catalogue.

import type { FC } from "react";

import type { CosmeticSlot } from "../avatarData";
import { AccessoryChain } from "./svgs/accessories";
import { BadgeBolt, BadgeDot, BadgeStar } from "./svgs/badges";
import { BgConfetti, BgSunburst, BgWash } from "./svgs/backgrounds";
import { EffectSparkle } from "./svgs/effects";
import { FrameDashed, FrameScallop, FrameSoft } from "./svgs/frames";
import { HatCrown, HatParty } from "./svgs/hats";

interface SlotMeta {
  id: CosmeticSlot;
  label: string;
  /** Layer order within <Avatar>; background sits under the photo. */
  z: number;
}

// Order here is also the order the editor renders its slot switcher.
export const COSMETIC_SLOTS: SlotMeta[] = [
  { id: "background", label: "Background", z: 0 },
  { id: "frame", label: "Frame", z: 3 },
  { id: "accessory", label: "Chain", z: 4 },
  { id: "hat", label: "Hat", z: 5 },
  { id: "badge", label: "Badge", z: 6 },
  { id: "effect", label: "Effect", z: 7 },
];

export const SLOT_Z: Record<CosmeticSlot, number> = Object.fromEntries(
  COSMETIC_SLOTS.map((s) => [s.id, s.z]),
) as Record<CosmeticSlot, number>;

type CosmeticSvg = FC<{ className?: string }>;

export const COSMETIC_SVGS: Record<string, CosmeticSvg> = {
  // frames
  frame_soft: FrameSoft,
  frame_dashed: FrameDashed,
  frame_scallop: FrameScallop,
  // hats
  hat_party: HatParty,
  hat_crown: HatCrown,
  // accessories
  accessory_chain: AccessoryChain,
  // badges
  badge_dot: BadgeDot,
  badge_star: BadgeStar,
  badge_bolt: BadgeBolt,
  // backgrounds
  bg_wash: BgWash,
  bg_confetti: BgConfetti,
  bg_sunburst: BgSunburst,
  // effects
  effect_sparkle: EffectSparkle,
};

export function cosmeticSvg(key: string | undefined): CosmeticSvg | null {
  return key ? (COSMETIC_SVGS[key] ?? null) : null;
}
