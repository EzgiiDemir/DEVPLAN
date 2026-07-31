"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Search, X, FolderKanban, ListChecks, Sparkles, MessageSquare, LayoutGrid } from "lucide-react";
import { useRouter } from "@/i18n/navigation";
import { apiFetch } from "@/lib/api";
import { useProject } from "@/lib/project-context";
import { useFocusTrap } from "@/lib/useFocusTrap";

const DEBOUNCE_MS = 300;

const GROUPS = [
  { key: "projects", Icon: FolderKanban },
  { key: "tasks", Icon: ListChecks },
  { key: "features", Icon: Sparkles },
  { key: "comments", Icon: MessageSquare },
  { key: "module_items", Icon: LayoutGrid },
];

export function GlobalSearch() {
  const t = useTranslations("Search");
  const router = useRouter();
  const { switchProject } = useProject();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [results, setResults] = useState(null);
  const [loading, setLoading] = useState(false);
  const dialogRef = useRef(null);
  useFocusTrap(dialogRef, () => setOpen(false));

  useEffect(() => {
    function handleShortcut(event) {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        setOpen(true);
      }
    }
    document.addEventListener("keydown", handleShortcut);
    return () => document.removeEventListener("keydown", handleShortcut);
  }, []);

  useEffect(() => {
    const trimmed = query.trim();

    if (trimmed.length < 2) {
      const clear = setTimeout(() => setResults(null), 0);
      return () => clearTimeout(clear);
    }

    const timer = setTimeout(() => {
      setLoading(true);
      apiFetch(`/search?q=${encodeURIComponent(trimmed)}`)
        .then(setResults)
        .catch(() => setResults(null))
        .finally(() => setLoading(false));
    }, DEBOUNCE_MS);

    return () => clearTimeout(timer);
  }, [query]);

  function close() {
    setOpen(false);
    setQuery("");
    setResults(null);
  }

  async function goToProject(projectId, destination) {
    await switchProject(projectId);
    close();
    router.push(destination);
  }

  const hasAnyResults = results && GROUPS.some(({ key }) => results[key]?.length > 0);

  return (
    <>
      <button
        onClick={() => setOpen(true)}
        className="w-9 h-9 rounded-full flex items-center justify-center text-dp-muted hover:text-dp-ink hover:bg-dp-faint transition-colors"
        aria-label={t("openLabel")}
      >
        <Search size={16} strokeWidth={1.8} />
      </button>

      {open && (
        <div
          className="fixed inset-0 bg-black/50 flex items-start justify-center z-50 p-4 pt-[10vh]"
          onClick={close}
        >
          <div
            ref={dialogRef}
            role="dialog"
            aria-modal="true"
            aria-label={t("title")}
            tabIndex={-1}
            className="w-full max-w-xl bg-dp-panel rounded-2xl shadow-[0_16px_50px_rgba(0,0,0,0.15)] overflow-hidden outline-none"
            onClick={(event) => event.stopPropagation()}
          >
            <div className="flex items-center gap-3 px-4 py-3 border-b border-dp-border">
              <Search size={16} className="text-dp-muted shrink-0" />
              <input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder={t("placeholder")}
                className="flex-1 bg-transparent text-sm outline-none placeholder:text-dp-muted"
              />
              <button onClick={close} aria-label={t("close")} className="text-dp-muted hover:text-dp-ink transition-colors">
                <X size={16} />
              </button>
            </div>

            <div className="max-h-[60vh] overflow-y-auto">
              {loading && <div className="px-4 py-6 text-center text-sm text-dp-muted">…</div>}

              {!loading && query.trim().length >= 2 && !hasAnyResults && (
                <div className="px-4 py-6 text-center text-sm text-dp-muted">{t("empty")}</div>
              )}

              {!loading && query.trim().length < 2 && (
                <div className="px-4 py-6 text-center text-sm text-dp-muted">{t("hint")}</div>
              )}

              {!loading &&
                results &&
                GROUPS.map(({ key, Icon }) => {
                  const items = results[key];
                  if (!items || items.length === 0) return null;

                  return (
                    <div key={key} className="py-2">
                      <div className="px-4 pb-1 text-[11px] font-semibold uppercase tracking-wide text-dp-muted-2">
                        {t(`groups.${key}`)}
                      </div>
                      <ul>
                        {items.map((item) => (
                          <li key={item.id}>
                            <button
                              onClick={() => goToProject(item.project_id ?? item.id, key === "projects" ? "/dashboard" : "/studio")}
                              className="w-full flex items-start gap-3 text-left px-4 py-2.5 hover:bg-dp-faint transition-colors"
                            >
                              <Icon size={15} className="text-dp-muted-2 mt-0.5 shrink-0" strokeWidth={1.8} />
                              <span className="min-w-0">
                                <span className="block text-sm font-medium truncate">
                                  {item.title || item.excerpt}
                                </span>
                                {item.project_title && key !== "projects" && (
                                  <span className="block text-xs text-dp-muted truncate">{item.project_title}</span>
                                )}
                              </span>
                            </button>
                          </li>
                        ))}
                      </ul>
                    </div>
                  );
                })}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
