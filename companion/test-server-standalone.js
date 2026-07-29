// Standalone harness: runs just the Express agent (no BrowserWindow/app),
// so the HTTP API logic can be verified in an environment without a real
// Electron GUI session (ELECTRON_RUN_AS_NODE=1). Not part of the shipped app.
const { createServer } = require("./src/server");
const { PairingStore } = require("./src/pairing");
const os = require("os");
const path = require("path");

const pairingStore = new PairingStore();
console.log("PAIRING CODE:", pairingStore.pairingCode);

const testBase = path.join(os.tmpdir(), "devplan-companion-e2e");
require("fs").mkdirSync(testBase, { recursive: true });

const server = createServer({
  pairingStore,
  getBasePaths: () => ({
    desktop: testBase,
    documents: testBase,
    downloads: testBase,
  }),
  onLog: (line) => console.log("[log]", line),
});

server.listen(58347, "127.0.0.1", () => {
  console.log("Standalone test agent listening on http://127.0.0.1:58347");
});
