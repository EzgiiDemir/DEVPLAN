import { Check, Wand2 } from "lucide-react";
import { useTranslations } from "next-intl";

export function TinyBtn({ children, onClick, danger, disabled }) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className={`text-xs font-medium px-2.5 py-1.5 rounded-lg border bg-dp-panel inline-flex items-center gap-1 transition-colors ${
        disabled
          ? "border-dp-border text-dp-muted cursor-not-allowed opacity-60"
          : danger
            ? "cursor-pointer border-red-200 text-red-500 hover:bg-red-50"
            : "cursor-pointer border-dp-border text-dp-ink hover:bg-dp-faint"
      }`}
    >
      {children}
    </button>
  );
}

export function AiBtn({ children, onClick, disabled }) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      className={`text-sm font-medium px-4 py-2 rounded-full border inline-flex items-center gap-1.5 transition-colors ${
        disabled
          ? "border-dp-border text-dp-muted bg-dp-faint cursor-not-allowed"
          : "border-dp-accent/30 text-dp-accent-strong bg-dp-accent-tint hover:bg-dp-accent/10 cursor-pointer"
      }`}
    >
      <Wand2 size={14} />
      {children}
    </button>
  );
}

export function CompleteButton({ enabled, isDone, onClick }) {
  const t = useTranslations("Common");
  return (
    <button
      disabled={!enabled}
      onClick={onClick}
      className={`text-sm font-semibold px-6 py-3.5 rounded-full mt-6 inline-flex items-center gap-2 border-none w-full sm:w-auto justify-center transition-all ${
        enabled
          ? isDone
            ? "bg-dp-green text-white shadow-[0_4px_16px_rgba(52,199,89,0.25)] cursor-pointer"
            : "bg-dp-solid text-dp-on-solid shadow-[0_4px_16px_rgba(0,0,0,0.15)] hover:opacity-90 cursor-pointer"
          : "bg-dp-border text-dp-muted cursor-not-allowed"
      }`}
    >
      {isDone && <Check size={16} />}
      {isDone ? t("completedButton") : t("completeButton")}
    </button>
  );
}
