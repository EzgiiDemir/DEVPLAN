const { app, BrowserWindow, ipcMain } = require("electron");
const path = require("path");
const { createServer } = require("./server");
const { PairingStore } = require("./pairing");

const PORT = 58347;
const pairingStore = new PairingStore();
let mainWindow;

function getBasePaths() {
  return {
    desktop: app.getPath("desktop"),
    documents: app.getPath("documents"),
    downloads: app.getPath("downloads"),
  };
}

function pushState() {
  if (!mainWindow) return;
  mainWindow.webContents.send("state", {
    pairingCode: pairingStore.pairingCode,
    pairedSessions: pairingStore.sessions.size,
    port: PORT,
  });
}

function pushLog(line) {
  if (!mainWindow) return;
  mainWindow.webContents.send("log", line);
}

function createWindow() {
  mainWindow = new BrowserWindow({
    width: 420,
    height: 640,
    resizable: false,
    webPreferences: {
      preload: path.join(__dirname, "preload.js"),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });
  mainWindow.setMenuBarVisibility(false);
  mainWindow.loadFile(path.join(__dirname, "renderer.html"));
  mainWindow.webContents.once("did-finish-load", pushState);
}

app.whenReady().then(() => {
  createWindow();

  const server = createServer({
    pairingStore,
    getBasePaths,
    onLog: (line) => {
      pushLog(line);
      pushState();
    },
  });

  server.listen(PORT, "127.0.0.1", () => {
    pushLog(`Local agent listening on http://127.0.0.1:${PORT}`);
  });

  ipcMain.on("regenerate-code", () => {
    pairingStore.regenerateCode();
    pushLog("Pairing code regenerated.");
    pushState();
  });

  app.on("activate", () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on("window-all-closed", () => {
  if (process.platform !== "darwin") app.quit();
});
