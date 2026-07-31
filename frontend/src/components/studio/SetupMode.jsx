"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Check, ExternalLink, FolderPlus, X } from "lucide-react";
import { useCompanion } from "@/lib/companion-context";
import { Chip } from "@/components/ui/Chip";
import { CompanionDownload } from "./CompanionDownload";

const LOCATIONS = ["desktop", "documents", "downloads", "custom"];

function slugify(value) {
  return (value || "my-project")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

export function SetupMode({ project, onProjectReady }) {
  const t = useTranslations("StudioSetup");
  const companion = useCompanion();
  const [code, setCode] = useState("");
  const [pairError, setPairError] = useState("");
  const [pairing, setPairing] = useState(false);

  const [tools, setTools] = useState(null);
  const [loadingTools, setLoadingTools] = useState(false);

  const [location, setLocation] = useState("desktop");
  const [customPath, setCustomPath] = useState("");
  const [name, setName] = useState(slugify(project.title));
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState("");

  useEffect(() => {
    if (!companion.paired) return;
    let cancelled = false;
    void Promise.resolve().then(() => {
      if (cancelled) return;
      setLoadingTools(true);
      companion
        .checkEnvironment()
        .then((result) => {
          if (!cancelled) setTools(result.tools);
        })
        .finally(() => {
          if (!cancelled) setLoadingTools(false);
        });
    });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [companion.paired]);

  async function handlePair(e) {
    e.preventDefault();
    setPairError("");
    setPairing(true);
    try {
      await companion.pair(code);
    } catch (err) {
      setPairError(err.message || t("pairError"));
    } finally {
      setPairing(false);
    }
  }

  async function handleCreate(e) {
    e.preventDefault();
    setCreateError("");
    setCreating(true);
    try {
      const result = await companion.createProject({
        location,
        customPath: location === "custom" ? customPath : undefined,
        name,
      });
      // Best-effort — a missing/failed git init shouldn't block getting into
      // Development Mode, but it's what makes checkpoint commits possible later.
      await companion.runCommand("git init", result.path).catch(() => {});
      onProjectReady(result.path);
    } catch (err) {
      setCreateError(err.message || t("createError"));
    } finally {
      setCreating(false);
    }
  }

  if (companion.checking) {
    return <p className="text-sm text-dp-muted p-8">{t("checkingCompanion")}</p>;
  }

  if (!companion.available) {
    return <CompanionDownload onRecheck={companion.ping} />;
  }

  if (!companion.paired) {
    return (
      <div className="max-w-sm mx-auto w-full px-4 sm:px-6 py-16">
        <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-3">
          {t("kicker")}
        </div>
        <h1 className="text-2xl font-bold tracking-tight mb-3">{t("pairHeading")}</h1>
        <p className="text-sm text-dp-muted mb-5 leading-relaxed">{t("pairBody")}</p>
        <form onSubmit={handlePair} className="flex flex-col gap-3">
          <input
            value={code}
            onChange={(e) => setCode(e.target.value.trim())}
            placeholder={t("pairPlaceholder")}
            maxLength={6}
            className="rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-3 text-lg font-mono tracking-widest text-center outline-none transition-colors"
          />
          {pairError && <p className="text-xs text-red-500">{pairError}</p>}
          <button
            type="submit"
            disabled={pairing || code.length !== 6}
            className="text-sm font-semibold px-4 py-3 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors"
          >
            {pairing ? t("pairing") : t("pairSubmit")}
          </button>
        </form>
      </div>
    );
  }

  return (
    <div className="max-w-2xl mx-auto w-full px-4 sm:px-6 py-12 sm:py-16">
      <div className="text-[11px] font-semibold text-dp-accent-strong uppercase tracking-wider mb-3">
        {t("kicker")}
      </div>
      <h1 className="text-2xl sm:text-3xl font-bold tracking-tight mb-1">{t("readyHeading")}</h1>
      <p className="text-sm text-dp-muted mb-8">{t("readySubtitle")}</p>

      <div className="bg-dp-panel rounded-2xl border border-dp-border p-5 mb-6">
        <div className="text-[11px] font-semibold text-dp-muted uppercase tracking-wider mb-3">
          {t("environmentHeading")}
        </div>
        <p className="text-xs text-dp-muted mb-3">{t("environmentNote")}</p>
        {loadingTools ? (
          <p className="text-sm text-dp-muted">{t("checkingTools")}</p>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
            {(tools || []).map((tool) => (
              <div key={tool.name} className="flex items-center justify-between gap-2 text-sm py-1">
                <span className="flex items-center gap-1.5">
                  {tool.installed ? (
                    <Check size={14} className="text-dp-green flex-shrink-0" />
                  ) : (
                    <X size={14} className="text-dp-muted flex-shrink-0" />
                  )}
                  {tool.name}
                  {tool.version && <span className="text-dp-muted text-xs">{tool.version}</span>}
                </span>
                {!tool.installed && (
                  <a
                    href={tool.downloadUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="text-xs text-dp-accent-strong inline-flex items-center gap-1"
                  >
                    {t("download")} <ExternalLink size={11} />
                  </a>
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      <form onSubmit={handleCreate} className="bg-dp-panel rounded-2xl border border-dp-border p-5">
        <div className="text-[11px] font-semibold text-dp-muted uppercase tracking-wider mb-3">
          {t("createHeading")}
        </div>
        <p className="text-xs text-dp-muted mb-3">{t("createLocationLabel")}</p>
        <div className="flex flex-wrap gap-1.5 mb-3">
          {LOCATIONS.map((loc) => (
            <Chip key={loc} active={location === loc} onClick={() => setLocation(loc)}>
              {t(`locations.${loc}`)}
            </Chip>
          ))}
        </div>
        {location === "custom" && (
          <input
            value={customPath}
            onChange={(e) => setCustomPath(e.target.value)}
            placeholder={t("customPathPlaceholder")}
            className="w-full mb-3 rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm outline-none transition-colors"
          />
        )}
        <input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="my-ecommerce"
          className="w-full mb-3 rounded-xl border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-4 py-2.5 text-sm font-mono outline-none transition-colors"
        />
        {createError && <p className="text-xs text-red-500 mb-3">{createError}</p>}
        <button
          type="submit"
          disabled={creating || !name.trim()}
          className="inline-flex items-center gap-1.5 text-sm font-semibold px-5 py-2.5 rounded-full bg-dp-solid text-dp-on-solid hover:opacity-90 disabled:opacity-50 transition-colors"
        >
          <FolderPlus size={15} /> {creating ? t("creating") : t("createSubmit")}
        </button>
      </form>
    </div>
  );
}
