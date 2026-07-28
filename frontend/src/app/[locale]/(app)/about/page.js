"use client";

import { useTranslations } from "next-intl";
import { Lightbulb, Search, Layers, TerminalSquare } from "lucide-react";
import { MayaAvatar } from "@/components/MayaAvatar";

const PILLARS = [
  { key: "validate", Icon: Lightbulb },
  { key: "research", Icon: Search },
  { key: "architect", Icon: Layers },
  { key: "ship", Icon: TerminalSquare },
];

export default function AboutPage() {
  const t = useTranslations("About");

  return (
    <div className="max-w-3xl mx-auto w-full px-4 sm:px-6 py-14 sm:py-20">
      <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-3">
        {t("kicker")}
      </div>
      <h1 className="text-3xl sm:text-4xl font-bold tracking-tight mb-5">{t("heading")}</h1>
      <p className="text-base text-dp-muted leading-relaxed mb-12 max-w-2xl">{t("intro")}</p>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-14">
        {PILLARS.map(({ key, Icon }) => (
          <div key={key} className="bg-dp-panel rounded-2xl border border-dp-border p-5">
            <div className="w-10 h-10 rounded-xl bg-dp-faint text-dp-accent-strong flex items-center justify-center mb-3">
              <Icon size={18} strokeWidth={1.8} />
            </div>
            <div className="text-sm font-semibold mb-1">{t(`pillars.${key}.title`)}</div>
            <p className="text-xs text-dp-muted leading-relaxed">{t(`pillars.${key}.body`)}</p>
          </div>
        ))}
      </div>

      <div className="bg-dp-panel rounded-[2rem] border border-dp-border p-7 sm:p-9 flex flex-col sm:flex-row items-start gap-6">
        <MayaAvatar className="w-16 h-16 flex-shrink-0" />
        <div>
          <h2 className="text-lg font-bold tracking-tight mb-2">{t("mayaHeading")}</h2>
          <p className="text-sm text-dp-muted leading-relaxed">{t("mayaBody")}</p>
        </div>
      </div>
    </div>
  );
}
