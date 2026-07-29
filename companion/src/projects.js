const fs = require("fs");
const path = require("path");

function sanitizeName(name) {
  return String(name || "")
    .trim()
    .replace(/[/\\:*?"<>|]/g, "-")
    .replace(/\.\./g, "-")
    .slice(0, 100);
}

// basePaths: { desktop, documents, downloads } — real OS folders, resolved by
// the Electron main process via app.getPath(), passed in so this module has
// no Electron dependency of its own.
function createProjectFolder({ location, customPath, name }, basePaths) {
  const safeName = sanitizeName(name);
  if (!safeName) {
    throw new Error("A project name is required.");
  }

  let base;
  if (location === "custom") {
    if (!customPath || !fs.existsSync(customPath) || !fs.statSync(customPath).isDirectory()) {
      throw new Error("The custom folder does not exist.");
    }
    base = customPath;
  } else {
    base = basePaths[location];
    if (!base) {
      throw new Error(`Unknown location: ${location}`);
    }
  }

  const fullPath = path.join(base, safeName);
  if (fs.existsSync(fullPath)) {
    throw new Error(`A folder named "${safeName}" already exists there.`);
  }

  fs.mkdirSync(fullPath, { recursive: true });
  return fullPath;
}

module.exports = { createProjectFolder, sanitizeName };
