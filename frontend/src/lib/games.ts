import type { LucideIcon } from "lucide-react";
import { Brain, PlaneTakeoff, Zap } from "lucide-react";

export interface GameDef {
  id: string;
  label: string;
  description: string;
  route?: string;
  icon?: LucideIcon;
}

export const PLAYABLE_GAMES: GameDef[] = [
  { id: "songle", label: "Songle", description: "Guess the song from a short clip.", route: "/songle" },
  {
    id: "dumbest-flies",
    label: "Der Dümmste fliegt",
    description: "Answer questions, vote out the dumbest answer.",
    route: "/ddf",
    icon: PlaneTakeoff,
  },
];

export const LOCKED_GAMES: GameDef[] = [
  { id: "trivia", label: "Trivia", description: "Coming soon", icon: Brain },
  { id: "reaction", label: "Reaction Challenge", description: "Coming soon", icon: Zap },
];
