import type { ReactNode } from "react";

interface DdfWebcamGridProps {
  children: ReactNode;
}

/** Responsive auto-fit grid for the player webcam tiles - same minmax technique as the Home page's game-picker grid. */
export function DdfWebcamGrid({ children }: DdfWebcamGridProps) {
  return <div className="ddf-webcam-grid">{children}</div>;
}
