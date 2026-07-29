const { contextBridge, ipcRenderer } = require("electron");

contextBridge.exposeInMainWorld("companion", {
  onState(callback) {
    ipcRenderer.on("state", (_event, state) => callback(state));
  },
  onLog(callback) {
    ipcRenderer.on("log", (_event, line) => callback(line));
  },
  regenerateCode() {
    ipcRenderer.send("regenerate-code");
  },
});
