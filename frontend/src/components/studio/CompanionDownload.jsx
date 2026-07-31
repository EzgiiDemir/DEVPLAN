"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Download, RefreshCw, Terminal } from "lucide-react";
import { AppleIcon } from "@/components/icons/AppleIcon";
import { WindowsIcon } from "@/components/icons/WindowsIcon";
import { LinuxIcon } from "@/components/icons/LinuxIcon";
import { TinyBtn } from "@/components/ui/Buttons";

const PLATFORMS = [
  { id: "win", Icon: WindowsIcon, envUrl: process.env.NEXT_PUBLIC_COMPANION_DOWNLOAD_WIN },
  { id: "mac", Icon: AppleIcon, envUrl: process.env.NEXT_PUBLIC_COMPANION_DOWNLOAD_MAC },
  { id: "linux", Icon: LinuxIcon, envUrl: process.env.NEXT_PUBLIC_COMPANION_DOWNLOAD_LINUX },
];

function detectPlatform() {
  if (typeof navigator === "undefined") return "win";
  const ua = navigator.userAgent;
  if (/Mac/i.test(ua)) return "mac";
  if (/Linux/i.test(ua) && !/Android/i.test(ua)) return "linux";
  return "win";
}

/**
 * The primary onboarding path for a non-developer end user: a real
 * installer download, not "clone the repo and run npm start" — that
 * instruction still exists (see the collapsed section below) as the
 * developer/advanced path, not the default one.
 */
export function CompanionDownload({ onRecheck }) {
  const t = useTranslations("StudioSetup");
  const [detected] = useState(detectPlatform);

  return (
    <div className="max-w-lg mx-auto w-full px-4 sm:px-6 py-16">
      <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-3">
        {t("kicker")}
      </div>
      <h1 className="text-2xl font-bold tracking-tight mb-3">{t("notRunningHeading")}</h1>
      <p className="text-sm text-dp-muted mb-6 leading-relaxed">{t("downloadBody")}</p>

      <div className="flex flex-col gap-2 mb-5">
        {PLATFORMS.map(({ id, Icon, envUrl }) => (
          <a
            key={id}
            href={envUrl || "#"}
            aria-disabled={!envUrl}
            onClick={(e) => {
              if (!envUrl) e.preventDefault();
            }}
            className={`flex items-center gap-3 px-4 py-3 rounded-xl border text-sm font-semibold transition-colors ${
              id === detected
                ? "border-dp-solid bg-dp-faint"
                : "border-dp-border hover:bg-dp-faint"
            } ${!envUrl ? "opacity-50 cursor-not-allowed" : ""}`}
          >
            <Icon size={18} className="flex-shrink-0" />
            <span className="flex-1">{t(`downloadPlatform.${id}`)}</span>
            <Download size={15} className="text-dp-muted-2 flex-shrink-0" />
          </a>
        ))}
      </div>

      <TinyBtn onClick={onRecheck}>
        <RefreshCw size={13} /> {t("recheck")}
      </TinyBtn>

      <details className="mt-8 text-xs text-dp-muted">
        <summary className="cursor-pointer flex items-center gap-1.5 font-semibold">
          <Terminal size={12} /> {t("runFromSourceSummary")}
        </summary>
        <p className="mt-2 leading-relaxed">{t("notRunningBody")}</p>
        <pre className="bg-dp-panel border border-dp-border rounded-xl p-4 text-xs font-mono mt-2 overflow-x-auto">
          cd companion{"\n"}npm install{"\n"}npm start
        </pre>
      </details>
    </div>
  );
}
