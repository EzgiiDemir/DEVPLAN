const fs = require("fs");
const path = require("path");

class ActiveProjectStore {
  constructor() {
    this.root = null;
  }

  register(projectPath) {
    if (!projectPath || !fs.existsSync(projectPath) || !fs.statSync(projectPath).isDirectory()) {
      throw new Error("That folder does not exist.");
    }
    this.root = path.resolve(projectPath);
    return this.root;
  }

  // Resolves a path the caller claims is relative to the project root, and
  // throws if it would actually escape the root (the same kind of check
  // createProjectFolder already does for new project names, applied here to
  // arbitrary nested paths instead of a single folder name).
  resolve(relativePath) {
    if (!this.root) {
      throw new Error("No active project. Register a project folder first.");
    }
    const full = path.resolve(this.root, relativePath || ".");
    if (full !== this.root && !full.startsWith(this.root + path.sep)) {
      throw new Error("That path is outside the active project.");
    }
    return full;
  }
}

module.exports = { ActiveProjectStore };
