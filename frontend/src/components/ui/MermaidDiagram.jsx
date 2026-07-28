"use client";

import { useEffect, useId, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { useTheme } from "@/lib/theme-context";

let mermaidModule = null;
async function loadMermaid() {
  if (!mermaidModule) {
    const mod = await import("mermaid");
    mermaidModule = mod.default;
  }
  return mermaidModule;
}

export function MermaidDiagram({ code, className = "" }) {
  const t = useTranslations("Common");
  const { theme } = useTheme();
  const containerRef = useRef(null);
  const diagramId = `mermaid-${useId().replace(/:/g, "")}`;
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;

    loadMermaid().then(async (mermaid) => {
      mermaid.initialize({
        startOnLoad: false,
        securityLevel: "strict",
        theme: theme === "dark" ? "dark" : "neutral",
        fontFamily: "var(--font-inter), sans-serif",
      });
      try {
        const { svg } = await mermaid.render(diagramId, code);
        if (cancelled) return;
        if (containerRef.current) containerRef.current.innerHTML = svg;
        setError("");
      } catch {
        if (!cancelled) setError(t("diagramError"));
      }
    });

    return () => {
      cancelled = true;
    };
  }, [code, theme, t, diagramId]);

  if (error) {
    return (
      <div className="rounded-xl border border-dashed border-dp-border bg-dp-faint p-4 text-xs text-dp-muted">
        {error}
      </div>
    );
  }

  return <div ref={containerRef} className={`overflow-x-auto ${className}`} />;
}
