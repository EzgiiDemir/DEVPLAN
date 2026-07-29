const fs = require("fs");
const path = require("path");

// The ONLY module allowed to touch .env* files — the opposite guard from
// files.js, which refuses them everywhere. Kept as its own module rather
// than sharing code with files.js on purpose, so the two code paths can
// never accidentally merge back into one that's unclear about which files
// it's allowed to touch. Used exclusively by the Environment Manager panel
// (Phase 9) — never wired into listFiles, the Project Brain scan, or any
// AI/Maya context.
const ENV_FILE_PATTERN = /^\.env/i;

function assertIsEnvFile(fullPath) {
  if (!ENV_FILE_PATTERN.test(path.basename(fullPath))) {
    throw new Error("This endpoint only reads/writes .env files.");
  }
}

function readEnvFile(fullPath) {
  assertIsEnvFile(fullPath);
  if (!fs.existsSync(fullPath)) {
    return "";
  }
  return fs.readFileSync(fullPath, "utf8");
}

function writeEnvFile(fullPath, content) {
  assertIsEnvFile(fullPath);
  fs.mkdirSync(path.dirname(fullPath), { recursive: true });
  fs.writeFileSync(fullPath, content ?? "", "utf8");
}

module.exports = { readEnvFile, writeEnvFile };
