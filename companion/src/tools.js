const { exec } = require("child_process");
const fs = require("fs");
const os = require("os");
const path = require("path");

function run(cmd) {
  return new Promise((resolve) => {
    exec(cmd, { timeout: 5000, windowsHide: true }, (error, stdout, stderr) => {
      if (error) {
        resolve({ ok: false, output: "" });
        return;
      }
      resolve({ ok: true, output: `${stdout}\n${stderr}`.trim() });
    });
  });
}

function extractVersion(output) {
  const match = output.match(/(\d+\.\d+(\.\d+)?)/);
  return match ? match[1] : output.split("\n")[0].slice(0, 60);
}

async function checkCliTool(name, cmd, downloadUrl, installGuideUrl) {
  const result = await run(cmd);
  return {
    name,
    installed: result.ok,
    version: result.ok ? extractVersion(result.output) : null,
    downloadUrl,
    installGuideUrl,
  };
}

function androidStudioPaths() {
  const platform = os.platform();
  if (platform === "win32") {
    const localAppData = process.env.LOCALAPPDATA || "";
    return [
      path.join(localAppData, "Google", "AndroidStudio"),
      "C:\\Program Files\\Android\\Android Studio",
    ];
  }
  if (platform === "darwin") {
    return ["/Applications/Android Studio.app"];
  }
  return ["/opt/android-studio", path.join(os.homedir(), "android-studio")];
}

async function checkAndroidStudio() {
  const candidates = androidStudioPaths();
  const found = candidates.some((p) => {
    try {
      return fs.existsSync(p);
    } catch {
      return false;
    }
  });
  return {
    name: "Android Studio",
    installed: found,
    version: found ? "detected" : null,
    downloadUrl: "https://developer.android.com/studio",
    installGuideUrl: "https://developer.android.com/studio/install",
    bestEffort: true,
  };
}

const TOOL_DEFINITIONS = [
  ["Node", "node --version", "https://nodejs.org/", "https://nodejs.org/en/download"],
  ["Git", "git --version", "https://git-scm.com/", "https://git-scm.com/downloads"],
  ["npm", "npm --version", "https://nodejs.org/", "https://nodejs.org/en/download"],
  ["pnpm", "pnpm --version", "https://pnpm.io/", "https://pnpm.io/installation"],
  ["Python", "python --version", "https://www.python.org/", "https://www.python.org/downloads/"],
  ["Java", "java -version", "https://adoptium.net/", "https://adoptium.net/installation/"],
  ["Docker", "docker --version", "https://www.docker.com/products/docker-desktop/", "https://docs.docker.com/get-docker/"],
  ["Flutter", "flutter --version", "https://flutter.dev/", "https://docs.flutter.dev/get-started/install"],
  ["Rust", "rustc --version", "https://www.rust-lang.org/", "https://www.rust-lang.org/tools/install"],
  ["Go", "go version", "https://go.dev/", "https://go.dev/doc/install"],
  [".NET", "dotnet --version", "https://dotnet.microsoft.com/", "https://dotnet.microsoft.com/en-us/download"],
  ["PHP", "php --version", "https://www.php.net/", "https://www.php.net/manual/en/install.php"],
  ["Composer", "composer --version", "https://getcomposer.org/", "https://getcomposer.org/download/"],
];

async function checkAllTools() {
  const cliResults = await Promise.all(
    TOOL_DEFINITIONS.map(([name, cmd, downloadUrl, installGuideUrl]) =>
      checkCliTool(name, cmd, downloadUrl, installGuideUrl),
    ),
  );
  const androidStudio = await checkAndroidStudio();
  return [...cliResults, androidStudio];
}

module.exports = { checkAllTools };
