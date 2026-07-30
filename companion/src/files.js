const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const EXCLUDED_DIRS = new Set([
  "node_modules", "vendor", ".git", "dist", "build", ".next", "out",
  "__pycache__", ".venv", "venv", ".idea", ".vscode",
  // Where the Testing Agent writes test-result/coverage output — working
  // data, not part of the project's own source.
  ".devplan",
]);

// Never even listed, let alone read — these can hold real secrets.
const ENV_FILE_PATTERN = /^\.env/i;

const BINARY_EXTENSIONS = new Set([
  ".png", ".jpg", ".jpeg", ".gif", ".webp", ".ico", ".svg", ".bmp",
  ".woff", ".woff2", ".ttf", ".eot", ".otf",
  ".zip", ".tar", ".gz", ".rar", ".7z",
  ".exe", ".dll", ".so", ".dylib", ".bin",
  ".pdf", ".mp4", ".mp3", ".mov", ".avi",
  ".sqlite", ".db",
]);

const MAX_READ_BYTES = 300 * 1024;

function shouldSkip(name) {
  return EXCLUDED_DIRS.has(name) || ENV_FILE_PATTERN.test(name);
}

function contentHash(fullPath, ext) {
  // Binary files aren't summarized, so hashing their content is wasted I/O —
  // size+mtime is a good enough change signal for files we'll never read anyway.
  if (BINARY_EXTENSIONS.has(ext)) return null;
  try {
    const buffer = fs.readFileSync(fullPath);
    return crypto.createHash("sha1").update(buffer).digest("hex");
  } catch {
    return null;
  }
}

function listFiles(root, dir = root, out = []) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const entry of entries) {
    if (shouldSkip(entry.name)) continue;
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      listFiles(root, fullPath, out);
    } else if (entry.isFile()) {
      const stat = fs.statSync(fullPath);
      const ext = path.extname(entry.name).toLowerCase();
      out.push({
        path: path.relative(root, fullPath).split(path.sep).join("/"),
        size: stat.size,
        mtimeMs: stat.mtimeMs,
        hash: contentHash(fullPath, ext),
      });
    }
  }
  return out;
}

function readFile(fullPath) {
  const name = path.basename(fullPath);
  if (ENV_FILE_PATTERN.test(name)) {
    throw new Error("Refusing to read an .env file — it may contain real secrets.");
  }
  const ext = path.extname(fullPath).toLowerCase();
  if (BINARY_EXTENSIONS.has(ext)) {
    throw new Error("Refusing to read a binary file.");
  }
  const stat = fs.statSync(fullPath);
  if (!stat.isFile()) {
    throw new Error("Not a file.");
  }
  if (stat.size > MAX_READ_BYTES) {
    throw new Error(`File is too large to read (${Math.round(stat.size / 1024)} KB, limit ${MAX_READ_BYTES / 1024} KB).`);
  }
  return fs.readFileSync(fullPath, "utf8");
}

function writeFile(fullPath, content) {
  if (ENV_FILE_PATTERN.test(path.basename(fullPath))) {
    throw new Error("Refusing to write an .env file through this endpoint — use the Environment Manager instead.");
  }
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, content, "utf8");
}

function deleteFile(fullPath) {
  // The one call site that had no .env guard at all, unlike
  // readFile/writeFile/renameFile — the same class of gap Phase 9 found and
  // fixed for writeFile.
  if (ENV_FILE_PATTERN.test(path.basename(fullPath))) {
    throw new Error("Refusing to delete an .env file through this endpoint.");
  }
  if (!fs.existsSync(fullPath)) return;
  fs.rmSync(fullPath, { force: true });
}

function renameFile(fullPath, newFullPath) {
  if (ENV_FILE_PATTERN.test(path.basename(fullPath)) || ENV_FILE_PATTERN.test(path.basename(newFullPath))) {
    throw new Error("Refusing to rename an .env file.");
  }
  if (!fs.existsSync(fullPath)) {
    throw new Error("Source file does not exist.");
  }
  fs.mkdirSync(path.dirname(newFullPath), { recursive: true });
  fs.renameSync(fullPath, newFullPath);
}

module.exports = { listFiles, readFile, writeFile, deleteFile, renameFile };
