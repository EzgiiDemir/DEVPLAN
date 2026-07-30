"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { ArrowLeft, ChevronDown, ChevronRight, File, Folder, Rocket, Share2 } from "lucide-react";
import { Link, useRouter } from "@/i18n/navigation";
import { useAuth } from "@/lib/auth-context";
import { useProject } from "@/lib/project-context";
import { useCompanion } from "@/lib/companion-context";
import { apiFetch } from "@/lib/api";
import { ThemeToggle } from "@/components/ThemeToggle";
import { RoadmapPanel } from "@/components/studio/RoadmapPanel";
import { ExecutionPlan } from "@/components/studio/ExecutionPlan";
import { SetupMode } from "@/components/studio/SetupMode";
import { TerminalPanel } from "@/components/studio/TerminalPanel";
import { ProjectBrainPanel } from "@/components/studio/ProjectBrainPanel";
import { MayaChatPanel } from "@/components/studio/MayaChatPanel";
import { CheckpointHistoryPanel } from "@/components/studio/CheckpointHistoryPanel";
import { FileExplorerPanel } from "@/components/studio/FileExplorerPanel";
import { IdeWorkspace } from "@/components/studio/IdeWorkspace";
import { LivePreviewPanel } from "@/components/studio/LivePreviewPanel";
import { TestingPanel } from "@/components/studio/TestingPanel";
import { DeploymentWizard } from "@/components/studio/DeploymentWizard";
import { ProjectSharingModal } from "@/components/studio/ProjectSharingModal";
import { TasksPanel } from "@/components/studio/TasksPanel";
import { ActivityFeedPanel } from "@/components/studio/ActivityFeedPanel";
import { CommentsPanel } from "@/components/studio/CommentsPanel";

const DEMO_TREE = {
  name: "project",
  type: "folder",
  children: [
    {
      name: "frontend",
      type: "folder",
      children: [
        {
          name: "src/app",
          type: "folder",
          children: [{ name: "page.js", type: "file" }],
        },
        { name: "package.json", type: "file" },
      ],
    },
    {
      name: "backend",
      type: "folder",
      children: [
        {
          name: "app/Http/Controllers",
          type: "folder",
          children: [{ name: "ProductController.php", type: "file" }],
        },
        {
          name: "routes",
          type: "folder",
          children: [{ name: "api.php", type: "file" }],
        },
      ],
    },
    { name: "README.md", type: "file" },
  ],
};

const DEMO_CONTENT = {
  "project/frontend/src/app/page.js": {
    lang: "js",
    text: [
      "export default function HomePage() {",
      "  return (",
      "    <main>",
      "      <h1>Welcome</h1>",
      "    </main>",
      "  );",
      "}",
    ].join("\n"),
  },
  "project/backend/app/Http/Controllers/ProductController.php": {
    lang: "php",
    text: [
      "class ProductController extends Controller",
      "{",
      "    public function index()",
      "    {",
      "        return Product::all();",
      "    }",
      "}",
    ].join("\n"),
  },
  "project/backend/routes/api.php": {
    lang: "php",
    text: "Route::get('/products', [ProductController::class, 'index']);",
  },
};

const KEYWORDS_JS = /\b(export|default|function|return|const|let)\b/g;
const KEYWORDS_PHP = /\b(class|extends|public|function|return)\b/g;

function highlight(line, lang) {
  if (lang !== "js" && lang !== "php") return <span>{line}</span>;
  const keywords = lang === "php" ? KEYWORDS_PHP : KEYWORDS_JS;
  const parts = line.split(keywords);
  return parts.map((part, i) =>
    keywords.test(part) ? (
      <span key={i} className="text-dp-accent-strong">
        {part}
      </span>
    ) : (
      <span key={i}>{part}</span>
    ),
  );
}

function collectFileNames(node, names = new Set()) {
  if (node.type === "folder") {
    for (const child of node.children || []) collectFileNames(child, names);
  } else {
    names.add(node.name.toLowerCase());
  }
  return names;
}

// The scaffold AI isn't guaranteed to include README/.gitignore/docker-compose/.env.example
// in the tree it generates. If the Environment module already produced real content for
// those files, make sure they show up in Studio regardless of what the scaffold contained.
function withGuaranteedEnvFiles(tree, envFiles) {
  if (!envFiles) return tree;
  const existing = collectFileNames(tree);
  const toAdd = [];
  if (envFiles.readme && !existing.has("readme.md")) toAdd.push({ name: "README.md", type: "file" });
  if (envFiles.gitignore && !existing.has(".gitignore")) toAdd.push({ name: ".gitignore", type: "file" });
  if (envFiles.dockerCompose && !existing.has("docker-compose.yml")) toAdd.push({ name: "docker-compose.yml", type: "file" });
  if (envFiles.envExample && !existing.has(".env.example")) toAdd.push({ name: ".env.example", type: "file" });
  if (toAdd.length === 0) return tree;
  return { ...tree, children: [...(tree.children || []), ...toAdd] };
}

function extensionLang(name) {
  if (name.endsWith(".php")) return "php";
  if (name.endsWith(".js") || name.endsWith(".jsx") || name.endsWith(".ts") || name.endsWith(".tsx")) return "js";
  return "text";
}

// Flattens the AI-generated {name, type, children} tree into a lookup of
// path -> file node, and derives placeholder/real content per file so the
// editor pane always has something to show for every leaf.
function flattenFiles(tree, envFiles, path = "") {
  const files = {};
  const nodePath = path ? `${path}/${tree.name}` : tree.name;

  if (tree.type === "folder") {
    for (const child of tree.children || []) {
      Object.assign(files, flattenFiles(child, envFiles, nodePath));
    }
    return files;
  }

  const lowerName = tree.name.toLowerCase();
  let content = null;
  let lang = extensionLang(tree.name);

  if (envFiles) {
    if (lowerName === "readme.md" && envFiles.readme) {
      content = envFiles.readme;
      lang = "md";
    } else if (lowerName === ".gitignore" && envFiles.gitignore) {
      content = envFiles.gitignore;
      lang = "text";
    } else if (lowerName === "docker-compose.yml" && envFiles.dockerCompose) {
      content = envFiles.dockerCompose;
      lang = "yaml";
    } else if (lowerName === ".env.example" && envFiles.envExample) {
      content = envFiles.envExample;
      lang = "text";
    }
  }

  files[nodePath] = {
    label: tree.name,
    lang,
    text: content,
    isReal: content !== null,
  };
  return files;
}

function TreeNode({ node, path, activeFile, onSelect, depth = 0 }) {
  const [open, setOpen] = useState(depth < 1);
  const nodePath = path ? `${path}/${node.name}` : node.name;

  if (node.type === "folder") {
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
            {(node.children || []).map((child, i) => (
              <TreeNode key={child.name + i} node={child} path={nodePath} activeFile={activeFile} onSelect={onSelect} depth={depth + 1} />
            ))}
          </div>
        )}
      </div>
    );
  }

  const isActive = nodePath === activeFile;
  return (
    <button
      onClick={() => onSelect(nodePath)}
      className={`flex items-center gap-1.5 w-full text-left px-2 py-1 rounded text-[13px] cursor-pointer ${
        isActive ? "bg-dp-editor-overlay text-dp-editor-text" : "text-dp-editor-muted hover:bg-dp-editor-overlay"
      }`}
      style={{ paddingLeft: `${depth * 14 + 26}px` }}
    >
      <File size={13} />
      {node.name}
    </button>
  );
}

function phaseKey(projectId) {
  return `devplan.studioPhase.${projectId}`;
}
function pathKey(projectId) {
  return `devplan.studioPath.${projectId}`;
}

export default function StudioPage() {
  const t = useTranslations("Studio");
  const tCommon = useTranslations("Common");
  const router = useRouter();
  const { user, loading: authLoading } = useAuth();
  const { project, loading: projectLoading, updateProject, canAct, canManage } = useProject();
  const companion = useCompanion();

  const [loadingArtifacts, setLoadingArtifacts] = useState(true);
  const [tree, setTree] = useState(null);
  const [isDemo, setIsDemo] = useState(true);
  const [envFiles, setEnvFiles] = useState(null);
  const [stack, setStack] = useState(null);
  const [activeFile, setActiveFile] = useState(null);

  const [phase, setPhase] = useState(null);
  const [localPath, setLocalPath] = useState(null);
  const [checkpointVersion, setCheckpointVersion] = useState(0);
  const [detectedPort, setDetectedPort] = useState(null);
  const [bottomTab, setBottomTab] = useState("terminal");
  const [showDeployWizard, setShowDeployWizard] = useState(false);
  const [showSharing, setShowSharing] = useState(false);

  useEffect(() => {
    if (!authLoading && !user) router.push("/login");
  }, [authLoading, user, router]);

  useEffect(() => {
    if (!project) return;
    void Promise.resolve().then(() => {
      setPhase(localStorage.getItem(phaseKey(project.id)) || "roadmap");
      // The server is the durable source of truth once a project has been
      // created via Companion; localStorage is only a fallback for projects
      // created before local_path started persisting server-side.
      setLocalPath(project.local_path || localStorage.getItem(pathKey(project.id)));
    });
  }, [project]);

  function goToPhase(next) {
    setPhase(next);
    if (project) localStorage.setItem(phaseKey(project.id), next);
  }

  async function handleProjectReady(path) {
    setLocalPath(path);
    if (project) {
      localStorage.setItem(pathKey(project.id), path);
      try {
        await updateProject(project.id, { local_path: path });
      } catch {
        // Non-fatal — localStorage still has it, and the next successful
        // save will persist it server-side.
      }
    }
    goToPhase("development");
  }

  // Resuming Development Mode in a fresh Companion session (e.g. it was
  // restarted since last time) — Companion's active-project memory is gone,
  // so re-register the already-known path rather than requiring the user
  // to recreate the folder.
  useEffect(() => {
    if (phase !== "development" || !localPath || !companion.paired) return;
    companion.registerProject(localPath).catch(() => {});
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [phase, localPath, companion.paired]);

  useEffect(() => {
    if (!project) return;
    let cancelled = false;

    async function load() {
      const modules = project.modules || [];
      const scaffoldModule = modules.find((m) => m.module_type === "folder_structure");
      const envModule = modules.find((m) => m.module_type === "environment");
      const stackModule = modules.find((m) => m.module_type === "tech_stack");

      let realTree = null;
      let realEnvFiles = null;
      let realStack = null;

      if (scaffoldModule) {
        const items = await apiFetch(`/modules/${scaffoldModule.id}/items`);
        const scaffoldItem = items.find((i) => i.item_type === "scaffold_tree");
        if (scaffoldItem) realTree = scaffoldItem.content.tree;
      }

      if (envModule) {
        const items = await apiFetch(`/modules/${envModule.id}/items`);
        const envItem = items.find((i) => i.item_type === "env_files");
        if (envItem) realEnvFiles = envItem.content.files;
      }

      if (stackModule) {
        const items = await apiFetch(`/modules/${stackModule.id}/items`);
        const stackItem = items.find((i) => i.item_type === "tech_stack");
        if (stackItem) {
          realStack = {
            frontend: stackItem.content.frontend?.selected,
            backend: stackItem.content.backend?.selected,
            database: stackItem.content.database?.selected,
          };
        }
      }

      if (cancelled) return;
      setTree(realTree ? withGuaranteedEnvFiles(realTree, realEnvFiles) : DEMO_TREE);
      setIsDemo(!realTree);
      setEnvFiles(realEnvFiles);
      setStack(realStack);
      setLoadingArtifacts(false);
    }

    load();
    return () => {
      cancelled = true;
    };
  }, [project]);

  if (authLoading || projectLoading || loadingArtifacts || !user || !phase) {
    return <div className="min-h-screen bg-dp-editor-bg text-sm text-dp-editor-muted flex items-center justify-center">{tCommon("loading")}</div>;
  }

  const fileMap = isDemo ? DEMO_CONTENT : flattenFiles(tree, envFiles);
  const filePaths = Object.keys(fileMap);
  const currentFile = activeFile && fileMap[activeFile] ? activeFile : filePaths[0];
  const file = fileMap[currentFile];

  // Once Companion is paired to a real project folder, Development Mode
  // switches from the read-only planning-artifact viewer to the real IDE
  // (Monaco + a real file explorer) backed by the actual files on disk.
  const useRealIde = companion.paired && !!localPath;

  const subheading = stack?.frontend
    ? t("subheadingStack", { frontend: stack.frontend, backend: stack.backend, database: stack.database })
    : t("subheading");

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
            {t(`phaseBadge.${phase}`)}
          </span>
        </div>
        <div className="flex items-center gap-3">
          {useRealIde && canManage && (
            <button
              type="button"
              onClick={() => setShowSharing(true)}
              className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-editor-overlay text-dp-editor-text"
            >
              <Share2 size={13} />
              {t("shareButton")}
            </button>
          )}
          {useRealIde && canAct && (
            <button
              type="button"
              onClick={() => setShowDeployWizard(true)}
              className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg bg-dp-accent text-white"
            >
              <Rocket size={13} />
              {t("deployButton")}
            </button>
          )}
          <span className="text-xs text-dp-editor-muted">{project?.title}</span>
          <ThemeToggle />
        </div>
      </div>

      {!canAct && (
        <p className="px-4 py-1.5 text-[11px] text-dp-editor-muted bg-dp-editor-overlay/40 border-b border-dp-editor-border">
          {t("viewerNotice")}
        </p>
      )}

      {showDeployWizard && (
        <DeploymentWizard
          projectId={project.id}
          localPath={localPath}
          onClose={() => setShowDeployWizard(false)}
          onDeployed={() => setCheckpointVersion((v) => v + 1)}
        />
      )}

      {showSharing && (
        <ProjectSharingModal projectId={project.id} teamId={project.team_id} onClose={() => setShowSharing(false)} />
      )}

      {phase === "roadmap" && <RoadmapPanel project={project} onContinue={() => goToPhase("execution-plan")} />}
      {phase === "execution-plan" && <ExecutionPlan project={project} onStart={() => goToPhase("setup")} />}
      {phase === "setup" && <SetupMode project={project} onProjectReady={handleProjectReady} />}

      {phase === "development" && (
        <>
          <p className="px-4 py-2 text-xs text-dp-editor-muted bg-dp-editor-bg border-b border-dp-editor-border">{subheading}</p>

          {isDemo && (
            <p className="px-4 py-2 text-xs text-dp-accent-strong bg-dp-accent/10 border-b border-dp-editor-border">
              {t("demoNotice")}{" "}
              <Link href="/modules/folder_structure" className="underline font-semibold">
                {t("demoNoticeLink")}
              </Link>
            </p>
          )}

          {localPath && (
            <p className="px-4 py-1.5 text-[11px] text-dp-editor-muted bg-dp-editor-bg border-b border-dp-editor-border font-mono truncate">
              {localPath}
            </p>
          )}

          <div className="flex flex-1 min-h-0">
            <div className="w-56 flex-shrink-0 bg-dp-editor-panel border-r border-dp-editor-border overflow-y-auto">
              <ProjectBrainPanel projectId={project.id} localPath={localPath} />
              <CheckpointHistoryPanel projectId={project.id} localPath={localPath} refreshKey={checkpointVersion} />
              {useRealIde ? (
                <FileExplorerPanel projectId={project.id} activeFile={activeFile} onSelectFile={setActiveFile} />
              ) : (
                <>
                  <div className="px-3 pt-2 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-dp-editor-muted">
                    {t("filesLabel")}
                  </div>
                  <TreeNode node={tree} path="" activeFile={currentFile} onSelect={setActiveFile} />
                </>
              )}
            </div>

            {useRealIde ? (
              <div className="flex-1 flex flex-col min-w-0">
                <IdeWorkspace
                  projectId={project.id}
                  fileToOpen={activeFile}
                  onActiveFileChange={setActiveFile}
                  initialWorkspaceState={project.workspace_state}
                />
                <div className="flex items-center gap-3 px-4 py-1 border-t border-dp-editor-border bg-dp-editor-panel text-[11px] flex-shrink-0">
                  <button
                    type="button"
                    onClick={() => setBottomTab("terminal")}
                    className={bottomTab === "terminal" ? "text-dp-editor-text font-semibold" : "text-dp-editor-muted"}
                  >
                    {t("terminalTab")}
                  </button>
                  <button
                    type="button"
                    onClick={() => setBottomTab("preview")}
                    className={bottomTab === "preview" ? "text-dp-editor-text font-semibold" : "text-dp-editor-muted"}
                  >
                    {t("previewTab")}
                  </button>
                  <button
                    type="button"
                    onClick={() => setBottomTab("tests")}
                    className={bottomTab === "tests" ? "text-dp-editor-text font-semibold" : "text-dp-editor-muted"}
                  >
                    {t("testsTab")}
                  </button>
                  <button
                    type="button"
                    onClick={() => setBottomTab("tasks")}
                    className={bottomTab === "tasks" ? "text-dp-editor-text font-semibold" : "text-dp-editor-muted"}
                  >
                    {t("tasksTab")}
                  </button>
                  <button
                    type="button"
                    onClick={() => setBottomTab("activity")}
                    className={bottomTab === "activity" ? "text-dp-editor-text font-semibold" : "text-dp-editor-muted"}
                  >
                    {t("activityTab")}
                  </button>
                  <button
                    type="button"
                    onClick={() => setBottomTab("discussion")}
                    className={bottomTab === "discussion" ? "text-dp-editor-text font-semibold" : "text-dp-editor-muted"}
                  >
                    {t("discussionTab")}
                  </button>
                </div>
                {/* TerminalPanel stays mounted even when another tab is active, so its
                    process polling and xterm instance survive switching tabs. */}
                <div style={{ display: bottomTab === "terminal" ? "block" : "none" }}>
                  <TerminalPanel cwd={localPath} projectId={project.id} onPortDetected={setDetectedPort} />
                </div>
                {bottomTab === "preview" && (
                  <div style={{ height: 240 }} className="flex-shrink-0">
                    <LivePreviewPanel detectedPort={detectedPort} />
                  </div>
                )}
                {bottomTab === "tests" && (
                  <div style={{ height: 240 }} className="flex-shrink-0">
                    <TestingPanel projectId={project.id} localPath={localPath} canAct={canAct} />
                  </div>
                )}
                {bottomTab === "tasks" && (
                  <div style={{ height: 240 }} className="flex-shrink-0">
                    <TasksPanel projectId={project.id} teamId={project.team_id} canAct={canAct} />
                  </div>
                )}
                {bottomTab === "activity" && (
                  <div style={{ height: 240 }} className="flex-shrink-0">
                    <ActivityFeedPanel projectId={project.id} />
                  </div>
                )}
                {bottomTab === "discussion" && (
                  <div style={{ height: 240 }} className="flex-shrink-0">
                    <CommentsPanel projectId={project.id} canAct={canAct} />
                  </div>
                )}
              </div>
            ) : (
              <div className="flex-1 flex flex-col min-w-0">
                <div className="flex items-center gap-2 px-4 py-2 text-xs border-b border-dp-editor-border bg-dp-editor-bg text-dp-editor-text flex-shrink-0">
                  <File size={12} />
                  {file?.label}
                  {file && !file.isReal && !isDemo && (
                    <span className="text-[10px] text-dp-editor-muted italic">{t("placeholderFile")}</span>
                  )}
                </div>
                <div className="flex-1 overflow-auto font-mono text-[13px] leading-6 p-4">
                  {file?.text ? (
                    file.text.split("\n").map((line, i) => (
                      <div key={i} className="flex gap-4">
                        <span className="text-dp-editor-muted select-none w-6 text-right flex-shrink-0">{i + 1}</span>
                        <span className="whitespace-pre text-dp-editor-text">{highlight(line, file.lang)}</span>
                      </div>
                    ))
                  ) : (
                    <p className="text-dp-editor-muted italic">{t("placeholderBody", { name: file?.label })}</p>
                  )}
                </div>
                <TerminalPanel cwd={localPath} projectId={project.id} />
              </div>
            )}

            <div className="w-80 flex-shrink-0 bg-dp-editor-panel border-l border-dp-editor-border flex flex-col">
              <MayaChatPanel
                projectId={project.id}
                localPath={localPath}
                activeFile={useRealIde ? activeFile : null}
                onApplied={() => setCheckpointVersion((v) => v + 1)}
                canAct={canAct}
              />
            </div>
          </div>
        </>
      )}
    </div>
  );
}
