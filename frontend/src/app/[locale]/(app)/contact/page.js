"use client";

import { useTranslations } from "next-intl";
import { Mail, Clock } from "lucide-react";

export default function ContactPage() {
  const t = useTranslations("Contact");

  return (
    <div className="max-w-2xl mx-auto w-full px-4 sm:px-6 py-14 sm:py-20">
      <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-3">
        {t("kicker")}
      </div>
      <h1 className="text-3xl sm:text-4xl font-bold tracking-tight mb-5">{t("heading")}</h1>
      <p className="text-base text-dp-muted leading-relaxed mb-10 max-w-xl">{t("intro")}</p>

      <div className="flex flex-col gap-3">
        <a
          href={`mailto:${t("email")}`}
          className="flex items-center gap-4 bg-dp-panel rounded-2xl border border-dp-border p-5 hover:border-dp-accent/40 transition-colors"
        >
          <div className="w-10 h-10 rounded-xl bg-dp-faint text-dp-accent-strong flex items-center justify-center flex-shrink-0">
            <Mail size={18} strokeWidth={1.8} />
          </div>
          <div>
            <div className="text-sm font-semibold mb-0.5">{t("emailLabel")}</div>
            <div className="text-sm text-dp-accent-strong">{t("email")}</div>
          </div>
        </a>

        <div className="flex items-center gap-4 bg-dp-panel rounded-2xl border border-dp-border p-5">
          <div className="w-10 h-10 rounded-xl bg-dp-faint text-dp-muted-2 flex items-center justify-center flex-shrink-0">
            <Clock size={18} strokeWidth={1.8} />
          </div>
          <div>
            <div className="text-sm font-semibold mb-0.5">{t("responseLabel")}</div>
            <p className="text-xs text-dp-muted leading-relaxed">{t("responseBody")}</p>
          </div>
        </div>
      </div>
    </div>
  );
}
