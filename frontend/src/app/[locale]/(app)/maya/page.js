"use client";

import { useTranslations } from "next-intl";
import { ChevronRight, MessageSquare, Wand2, GitBranch } from "lucide-react";
import { Link } from "@/i18n/navigation";
import { useAuth } from "@/lib/auth-context";
import { MayaAvatar } from "@/components/MayaAvatar";

const CAPABILITIES = [
  { key: "guides", Icon: GitBranch },
  { key: "generates", Icon: Wand2 },
  { key: "explains", Icon: MessageSquare },
];

export default function MayaIntroPage() {
  const t = useTranslations("MayaIntro");
  const { user } = useAuth();
  const firstName = user?.name?.split(" ")[0] ?? "";

  return (
    <div className="max-w-3xl mx-auto w-full px-4 sm:px-6 py-14 sm:py-20">
      <div className="flex flex-col sm:flex-row items-start sm:items-center gap-6 mb-10">
        <MayaAvatar className="w-20 h-20 flex-shrink-0" />
        <div>
          <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-2">
            {t("kicker")}
          </div>
          <h1 className="text-3xl sm:text-4xl font-bold tracking-tight">{t("heading", { name: firstName })}</h1>
        </div>
      </div>

      <p className="text-base text-dp-muted leading-relaxed mb-12 max-w-2xl">{t("intro")}</p>

      <div className="flex flex-col gap-3 mb-12">
        {CAPABILITIES.map(({ key, Icon }) => (
          <div key={key} className="flex items-start gap-4 bg-dp-panel rounded-2xl border border-dp-border p-5">
            <div className="w-10 h-10 rounded-xl bg-dp-faint text-dp-accent-strong flex items-center justify-center flex-shrink-0">
              <Icon size={18} strokeWidth={1.8} />
            </div>
            <div>
              <div className="text-sm font-semibold mb-1">{t(`capabilities.${key}.title`)}</div>
              <p className="text-xs text-dp-muted leading-relaxed">{t(`capabilities.${key}.body`)}</p>
            </div>
          </div>
        ))}
      </div>

      <div className="bg-dp-solid text-dp-on-solid rounded-[2rem] p-7 sm:p-9 flex flex-col sm:flex-row items-center justify-between gap-6">
        <div>
          <h2 className="text-lg font-bold tracking-tight mb-1.5">{t("ctaHeading")}</h2>
          <p className="text-sm opacity-80 max-w-md">{t("ctaBody")}</p>
        </div>
        <Link
          href="/dashboard"
          className="inline-flex items-center gap-1.5 px-5 py-2.5 rounded-full bg-dp-on-solid text-dp-solid text-sm font-semibold hover:opacity-90 transition-colors flex-shrink-0"
        >
          {t("cta")} <ChevronRight size={16} />
        </Link>
      </div>
    </div>
  );
}
