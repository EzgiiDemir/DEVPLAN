"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { RotateCw } from "lucide-react";

// Scope: an iframe on localhost:<port> with a refresh button. Real
// console.log/error interception across origins isn't attempted — that
// needs cooperation from the target page. In practice Next.js/Vite/CRA all
// render their own build/runtime error overlays directly in the page, so
// those already show up here for free.
export function LivePreviewPanel({ detectedPort }) {
  const t = useTranslations("StudioLivePreview");
  const [port, setPort] = useState(detectedPort || "");
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    if (detectedPort) void Promise.resolve().then(() => setPort(detectedPort));
  }, [detectedPort]);

  const url = port ? `http://localhost:${port}` : null;

  return (
    <div className="h-full flex flex-col">
      <div className="flex items-center gap-2 px-3 py-1.5 border-b border-dp-editor-border flex-shrink-0">
        <span className="text-[11px] text-dp-editor-muted">http://localhost:</span>
        <input
          value={port}
          onChange={(e) => setPort(e.target.value.replace(/\D/g, ""))}
          placeholder={t("portPlaceholder")}
          className="w-16 bg-dp-editor-overlay rounded px-1.5 py-0.5 text-[11px] font-mono text-dp-editor-text outline-none"
        />
        <button
          type="button"
          onClick={() => setReloadKey((k) => k + 1)}
          disabled={!url}
          title={t("refresh")}
          className="text-dp-editor-muted hover:text-dp-editor-text disabled:opacity-30"
        >
          <RotateCw size={12} />
        </button>
        {detectedPort && (
          <span className="text-[10px] text-dp-editor-muted italic">{t("autoDetected", { port: detectedPort })}</span>
        )}
      </div>
      <div className="flex-1 min-h-0 bg-white">
        {url ? (
          <iframe key={reloadKey} src={url} title="Live preview" className="w-full h-full border-0" />
        ) : (
          <p className="p-4 text-[12px] text-dp-editor-muted italic">{t("noPort")}</p>
        )}
      </div>
    </div>
  );
}
