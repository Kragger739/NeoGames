import type { ButtonHTMLAttributes } from "react";
import type { LucideIcon } from "lucide-react";

interface IconButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  icon: LucideIcon;
  label: string;
  variant?: "ghost" | "coral" | "turquoise" | "danger";
}

/** Small circular icon-only button — leave/close/copy/mute actions. */
export function IconButton({ icon: Icon, label, variant = "ghost", className, ...props }: IconButtonProps) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      className={["icon-btn", `icon-btn-${variant}`, className].filter(Boolean).join(" ")}
      {...props}
    >
      <Icon size={18} strokeWidth={2.25} />
    </button>
  );
}
