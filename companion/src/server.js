const express = require("express");
const cors = require("cors");
const { checkAllTools } = require("./tools");
const { createProjectFolder } = require("./projects");
const { runCommand } = require("./commands");
const { listFiles, readFile, writeFile, deleteFile, renameFile } = require("./files");
const { ActiveProjectStore } = require("./activeProject");
const { ProcessManager } = require("./processes");
const { readEnvFile, writeEnvFile } = require("./envFiles");

// TODO: add the production DevPlan domain here once it's deployed.
const ALLOWED_ORIGINS = ["http://localhost:3000", "http://127.0.0.1:3000"];

function createServer({ pairingStore, getBasePaths, onLog }) {
  const activeProject = new ActiveProjectStore();
  const processManager = new ProcessManager();
  const app = express();
  app.use(express.json());
  app.use(
    cors({
      origin(origin, callback) {
        if (!origin || ALLOWED_ORIGINS.includes(origin)) {
          callback(null, true);
        } else {
          callback(new Error("Origin not allowed"));
        }
      },
    }),
  );

  const log = onLog || (() => {});

  app.get("/health", (req, res) => {
    res.json({ status: "ok", version: require("../package.json").version });
  });

  app.post("/pair", (req, res) => {
    const { code } = req.body || {};
    const token = pairingStore.pair(code);
    if (!token) {
      log(`Pairing attempt failed (wrong code).`);
      res.status(401).json({ message: "Invalid pairing code." });
      return;
    }
    log(`Paired with a new browser session.`);
    res.json({ token });
  });

  function requireSession(req, res, next) {
    const token = (req.headers.authorization || "").replace(/^Bearer\s+/i, "");
    if (!pairingStore.isValidSession(token)) {
      res.status(401).json({ message: "Not paired. Enter the pairing code in DevPlan Studio first." });
      return;
    }
    next();
  }

  app.get("/environment-check", requireSession, async (req, res) => {
    const tools = await checkAllTools();
    res.json({ tools });
  });

  app.post("/project/create", requireSession, (req, res) => {
    try {
      const fullPath = createProjectFolder(req.body || {}, getBasePaths());
      processManager.stopAll();
      activeProject.register(fullPath);
      log(`Created project folder: ${fullPath}`);
      res.status(201).json({ path: fullPath });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.post("/project/register", requireSession, (req, res) => {
    try {
      const previousRoot = activeProject.root;
      const root = activeProject.register(req.body?.path);
      // Only kill background processes when this is genuinely a *different*
      // project — the frontend re-registers the same path on every
      // Development Mode remount/dependency change, and that shouldn't kill
      // a dev server the user just started.
      if (previousRoot && previousRoot !== root) {
        processManager.stopAll();
      }
      log(`Registered active project: ${root}`);
      res.json({ path: root });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.get("/project/files", requireSession, (req, res) => {
    try {
      const root = activeProject.resolve(".");
      res.json({ files: listFiles(root) });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.post("/file/read", requireSession, (req, res) => {
    try {
      const fullPath = activeProject.resolve(req.body?.path);
      res.json({ content: readFile(fullPath) });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.post("/file/write", requireSession, (req, res) => {
    try {
      const fullPath = activeProject.resolve(req.body?.path);
      writeFile(fullPath, req.body?.content ?? "");
      log(`Wrote file: ${req.body?.path}`);
      res.json({ path: req.body?.path });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.post("/file/delete", requireSession, (req, res) => {
    try {
      const fullPath = activeProject.resolve(req.body?.path);
      deleteFile(fullPath);
      log(`Deleted file: ${req.body?.path}`);
      res.json({ path: req.body?.path });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.post("/file/rename", requireSession, (req, res) => {
    try {
      const fullPath = activeProject.resolve(req.body?.path);
      const newFullPath = activeProject.resolve(req.body?.newPath);
      renameFile(fullPath, newFullPath);
      log(`Renamed file: ${req.body?.path} -> ${req.body?.newPath}`);
      res.json({ path: req.body?.newPath });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  // The only two endpoints allowed to touch .env* files (Phase 9's
  // Environment Manager) — deliberately separate from /file/read|write
  // above, which keep refusing .env* exactly as before. Never wired into
  // listFiles, the Project Brain scan, or any AI/Maya context.
  app.post("/env/read", requireSession, (req, res) => {
    try {
      const fullPath = activeProject.resolve(req.body?.path);
      res.json({ content: readEnvFile(fullPath) });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.post("/env/write", requireSession, (req, res) => {
    try {
      const fullPath = activeProject.resolve(req.body?.path);
      writeEnvFile(fullPath, req.body?.content ?? "");
      log(`Wrote env file: ${req.body?.path}`);
      res.json({ path: req.body?.path });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.post("/run", requireSession, async (req, res) => {
    try {
      log(`Running: ${req.body?.command} (in ${req.body?.cwd})`);
      const result = await runCommand(req.body || {});
      res.json(result);
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.post("/process/start", requireSession, (req, res) => {
    try {
      const { command, cwd } = req.body || {};
      const result = processManager.start({ command, cwd });
      log(`Started background process ${result.id}: ${command} (in ${cwd})`);
      res.status(201).json(result);
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.get("/process/:id/output", requireSession, (req, res) => {
    try {
      const since = Number(req.query.since) || 0;
      res.json(processManager.getOutput(req.params.id, since));
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.post("/process/:id/input", requireSession, (req, res) => {
    try {
      processManager.writeInput(req.params.id, req.body?.data ?? "");
      res.json({ ok: true });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.post("/process/:id/stop", requireSession, (req, res) => {
    try {
      processManager.stop(req.params.id);
      log(`Stopped background process ${req.params.id}`);
      res.json({ ok: true });
    } catch (err) {
      res.status(422).json({ message: err.message });
    }
  });

  app.get("/process/list", requireSession, (req, res) => {
    res.json({ processes: processManager.list() });
  });

  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    if (err.message === "Origin not allowed") {
      res.status(403).json({ message: "This site is not allowed to talk to DevPlan Companion." });
      return;
    }
    res.status(500).json({ message: "Unexpected error." });
  });

  return app;
}

module.exports = { createServer, ALLOWED_ORIGINS };
