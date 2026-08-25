import { AxiosError } from "axios";

export function firstValidationError(error: unknown): string {
  if (error instanceof AxiosError) {
    const errors = error.response?.data?.errors as
      | Record<string, string[]>
      | undefined;
    if (errors) {
      const first = Object.values(errors)[0]?.[0];
      if (first) return first;
    }
    const message = error.response?.data?.message as string | undefined;
    if (message) return message;
  }
  return "Something went wrong. Please try again.";
}
