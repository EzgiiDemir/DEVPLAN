"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ArrowLeft, ChevronDown, ChevronRight, File, Folder, Send } from "lucide-react";
import { Link, useRouter } from "@/i18n/navigation";
import { useAuth } from "@/lib/auth-context";
import { useProject } from "@/lib/project-context";
import { MayaAvatar } from "@/components/MayaAvatar";
import { ThemeToggle } from "@/components/ThemeToggle";

const FILES = {
  "frontend/src/app/page.js": {
    label: "page.js",
    lang: "js",
    lines: [
      "export default function HomePage() {",
      "  return (",
      "    <main>",
      "      <h1>Welcome</h1>",
      "    </main>",
      "  );",
      "}",
    ],
  },
  "backend/app/Http/Controllers/ProductController.php": {
    label: "ProductController.php",
    lang: "php",
    lines: [
      "class ProductController extends Controller",
      "{",
      "    public function index()",
      "    {",
      "        return Product::all();",
      "    }",
      "}",
    ],
  },
  "backend/routes/api.php": {
    label: "api.php",
    lang: "php",
    lines: ["Route::get('/products', [ProductController::class, 'index']);"],
  },
};

const TREE = [
  {
    name: "frontend",
    children: [
      {
        name: "src/app",
        children: [{ name: "page.js", path: "frontend/src/app/page.js" }],
      },
      { name: "package.json" },
    ],
  },
  {
    name: "backend",
    children: [
      {
        name: "app/Http/Controllers",
        children: [{ name: "ProductController.php", path: "backend/app/Http/Controllers/ProductController.php" }],
      },
      {
        name: "routes",
        children: [{ name: "api.php", path: "backend/routes/api.php" }],
      },
    ],
  },
  { name: "README.md" },
];

const KEYWORDS_JS = /\b(export|default|function|return|const|let)\b/g;
const KEYWORDS_PHP = /\b(class|extends|public|function|return)\b/g;

function highlight(line, lang) {
  const keywords = lang === "php" ? KEYWORDS_PHP : KEYWORDS_JS;
  const parts = line.split(keywords);
  return parts.map((part, i) =>
    (lang === "php" ? KEYWORDS_PHP : KEYWORDS_JS).test(part) ? (
      <span key={i} className="text-dp-accent-strong">
        {part}
      </span>
    ) : (
      <span key={i}>{part}</span>
    ),
  );
}

function TreeNode({ node, activeFile, onSelect, depth = 0 }) {
  const [open, setOpen] = useState(true);

  if (node.children) {
    return (
      <div>
        <button
          onClick={() => setOpen(!open)}
          className="flex items-center gap-1.5 w-full text-left px-2 py-1 rounded text-[13px] text-dp-editor-muted hover:bg-dp-editor-overlay"
          style={{ paddingLeft: `${depth * 14 + 8}px` }}
        >
          {open ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
          <Folder size={14} className="text-dp-accent" />
          {node.name}
        </button>
        {open && (
          <div>
            {node.children.map((child) => (
              <TreeNode key={child.name} node={child} activeFile={activeFile} onSelect={onSelect} depth={depth + 1} />
            ))}
          </div>
        )}
      </div>
    );
  }

  const isActive = node.path && node.path === activeFile;
  return (
    <button
      onClick={() => node.path && onSelect(node.path)}
      className={`flex items-center gap-1.5 w-full text-left px-2 py-1 rounded text-[13px] ${
        isActive ? "bg-dp-editor-overlay text-dp-editor-text" : "text-dp-editor-muted hover:bg-dp-editor-overlay"
      } ${node.path ? "cursor-pointer" : "cursor-default opacity-60"}`}
      style={{ paddingLeft: `${depth * 14 + 26}px` }}
    >
      <File size={13} />
      {node.name}
    </button>
  );
}

export default function StudioPage() {
  const t = useTranslations("Studio");
  const tCommon = useTranslations("Common");
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();
  const { project, loading: projectLoading } = useProject();
  const openFiles = Object.keys(FILES);
  const [activeFile, setActiveFile] = useState(openFiles[0]);

  useEffect(() => {
    if (!authLoading && !user) router.push("/login");
  }, [authLoading, user, router]);

  if (authLoading || projectLoading || !user) {
    return <div className="min-h-screen bg-dp-editor-bg text-sm text-dp-editor-muted flex items-center justify-center">{tCommon("loading")}</div>;
  }

  const chat = t.raw("chat");
  const file = FILES[activeFile];

  return (
    <div className="min-h-screen bg-dp-editor-bg text-dp-editor-text flex flex-col">
      <div className="flex items-center justify-between px-4 py-2.5 border-b border-dp-editor-border bg-dp-editor-panel">
        <div className="flex items-center gap-3">
          <Link href="/dashboard" className="text-dp-editor-muted hover:text-dp-editor-text transition-colors flex items-center gap-1.5 text-sm">
            <ArrowLeft size={15} /> {tCommon("back")}
          </Link>
          <span className="text-dp-editor-border">|</span>
          <span className="text-sm font-medium text-dp-editor-text">{t("heading")}</span>
          <span className="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded bg-dp-accent/20 text-dp-accent-strong">
            {t("badge")}
          </span>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-xs text-dp-editor-muted">{project?.title}</span>
          <ThemeToggle />
        </div>
      </div>

      <p className="px-4 py-2 text-xs text-dp-editor-muted bg-dp-editor-bg border-b border-dp-editor-border">{t("subheading")}</p>

      <div className="flex flex-1 min-h-0">
        <div className="w-56 flex-shrink-0 bg-dp-editor-panel border-r border-dp-editor-border py-2 overflow-y-auto">
          <div className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-dp-editor-muted">
            {t("filesLabel")}
          </div>
          {TREE.map((node) => (
            <TreeNode key={node.name} node={node} activeFile={activeFile} onSelect={setActiveFile} />
          ))}
        </div>

        <div className="flex-1 flex flex-col min-w-0">
          <div className="flex border-b border-dp-editor-border bg-dp-editor-bg overflow-x-auto">
            {openFiles.map((path) => (
              <button
                key={path}
                onClick={() => setActiveFile(path)}
                className={`flex items-center gap-2 px-4 py-2 text-xs border-r border-dp-editor-border whitespace-nowrap ${
                  activeFile === path ? "bg-dp-editor-bg text-dp-editor-text border-t-2 border-t-dp-accent" : "text-dp-editor-muted hover:text-dp-editor-text"
                }`}
              >
                <File size={12} />
                {FILES[path].label}
              </button>
            ))}
          </div>
          <div className="flex-1 overflow-auto font-mono text-[13px] leading-6 p-4">
            {file.lines.map((line, i) => (
              <div key={i} className="flex gap-4">
                <span className="text-dp-editor-muted select-none w-6 text-right flex-shrink-0">{i + 1}</span>
                <span className="whitespace-pre text-dp-editor-text">{highlight(line, file.lang)}</span>
              </div>
            ))}
          </div>
        </div>

        <div className="w-80 flex-shrink-0 bg-dp-editor-panel border-l border-dp-editor-border flex flex-col">
          <div className="flex items-center gap-2 px-4 py-3 border-b border-dp-editor-border">
            <MayaAvatar className="w-7 h-7" />
            <span className="text-sm font-semibold text-dp-editor-text">{t("mayaLabel")}</span>
          </div>
          <div className="flex-1 overflow-y-auto p-4 flex flex-col gap-3">
            {chat.map((msg, i) => (
              <div
                key={i}
                className={`text-[13px] leading-relaxed px-3.5 py-2.5 rounded-2xl max-w-[85%] ${
                  msg.from === "maya"
                    ? "bg-dp-editor-overlay text-dp-editor-text self-start rounded-tl-sm"
                    : "bg-dp-accent text-white self-end rounded-tr-sm"
                }`}
              >
                {msg.text}
              </div>
            ))}
          </div>
          <div className="p-3 border-t border-dp-editor-border">
            <div className="flex items-center gap-2 bg-dp-editor-overlay rounded-full px-3.5 py-2.5 opacity-60">
              <input
                disabled
                placeholder={t("inputPlaceholder")}
                className="flex-1 bg-transparent text-xs text-dp-editor-text placeholder:text-dp-editor-muted outline-none cursor-not-allowed"
              />
              <Send size={14} className="text-dp-editor-muted" />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
