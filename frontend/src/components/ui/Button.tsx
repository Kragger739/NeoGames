import { type ButtonHTMLAttributes, forwardRef } from "react";

type ButtonVariant = "primary" | "turquoise" | "grape" | "ghost" | "danger";

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: "md" | "lg";
}

/**
 * Every button in the app renders through this — a plain `<button
 * className="...">` here is a lapse (see DESIGN.md). Variants pick which
 * candy hue owns the fill; ghost is the only outlined-at-rest option, used
 * next to a primary action rather than instead of one.
 */
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = "primary", size = "md", className, ...props },
  ref,
) {
  const classes = ["btn", `btn-${variant}`, size === "lg" ? "btn-lg" : "", className]
    .filter(Boolean)
    .join(" ");

  return <button ref={ref} className={classes} {...props} />;
});
