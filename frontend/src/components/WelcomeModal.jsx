"use client";

import { useRef } from "react";
import { useTranslations } from "next-intl";
import { X, Lightbulb, Rocket, Bot } from "lucide-react";
import { MayaAvatar } from "@/components/MayaAvatar";
import { useFocusTrap } from "@/lib/useFocusTrap";

/**
 * A one-time welcome walkthrough, dismissed via a real backend flag
 * (onboarding_completed_at on the user) rather than only a localStorage
 * marker — so it doesn't reappear on a different device/browser.
 */
export function WelcomeModal({ firstName, onDismiss, dismissing }) {
  const t = useTranslations("Welcome");
  const dialogRef = useRef(null);
  useFocusTrap(dialogRef, () => {
    if (!dismissing) onDismiss();
  });

  const steps = [
    { Icon: Lightbulb, key: "modules" },
    { Icon: Bot, key: "maya" },
    { Icon: Rocket, key: "studio" },
  ];

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="welcome-modal-title"
        tabIndex={-1}
        className="w-full max-w-lg bg-dp-panel rounded-[1.75rem] shadow-[0_16px_50px_rgba(0,0,0,0.15)] p-8 relative outline-none"
      >
        <button
          type="button"
          onClick={onDismiss}
          disabled={dismissing}
          aria-label={t("dismiss")}
          className="absolute top-5 right-5 text-dp-muted hover:text-dp-ink transition-colors"
        >
          <X size={18} />
        </button>

        <MayaAvatar className="w-14 h-14 mb-4" />
        <h2 id="welcome-modal-title" className="text-xl font-bold tracking-tight mb-1.5">{t("heading", { name: firstName })}</h2>
        <p className="text-sm text-dp-muted mb-6">{t("subheading")}</p>

        <div className="flex flex-col gap-3 mb-7">
          {steps.map(({ Icon, key }) => (
            <div key={key} className="flex items-start gap-3">
              <div className="w-9 h-9 rounded-xl bg-dp-faint text-dp-muted-2 flex items-center justify-center flex-shrink-0">
                <Icon size={17} strokeWidth={1.6} />
              </div>
              <div>
                <div className="text-sm font-semibold">{t(`steps.${key}.title`)}</div>
                <div className="text-xs text-dp-muted leading-relaxed">{t(`steps.${key}.body`)}</div>
              </div>
            </div>
          ))}
        </div>

        <button
          type="button"
          onClick={onDismiss}
          disabled={dismissing}
          className="text-sm font-semibold px-5 py-3 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors w-full"
        >
          {dismissing ? t("dismissing") : t("cta")}
        </button>
      </div>
    </div>
  );
}
