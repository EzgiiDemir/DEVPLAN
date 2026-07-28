"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Check, Copy } from "lucide-react";

export function FilePanel({ name, content }) {
  const t = useTranslations("Common");
  const [copied, setCopied] = useState(false);

  async function copy() {
    await navigator.clipboard.writeText(content);
    setCopied(true);
    setTimeout(() => setCopied(false), 1500);
  }

  return (
    <div className="bg-dp-panel rounded-2xl border border-dp-border overflow-hidden">
      <div className="flex items-center justify-between px-4 py-2.5 border-b border-dp-border bg-dp-faint">
        <span className="font-mono text-xs font-semibold">{name}</span>
        <button
          type="button"
          onClick={copy}
          className="inline-flex items-center gap-1 text-xs font-medium text-dp-muted hover:text-dp-ink transition-colors"
        >
          {copied ? <Check size={12} className="text-dp-green" /> : <Copy size={12} />}
          {copied ? t("copied") : t("copy")}
        </button>
      </div>
      <pre className="text-xs leading-relaxed p-4 overflow-x-auto whitespace-pre-wrap m-0 max-h-72 overflow-y-auto">
        {content}
      </pre>
    </div>
  );
}
