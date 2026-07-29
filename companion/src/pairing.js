const crypto = require("crypto");

function generatePairingCode() {
  return String(crypto.randomInt(100000, 999999));
}

class PairingStore {
  constructor() {
    this.pairingCode = generatePairingCode();
    this.sessions = new Set();
  }

  regenerateCode() {
    this.pairingCode = generatePairingCode();
    return this.pairingCode;
  }

  pair(code) {
    if (code !== this.pairingCode) return null;
    const token = crypto.randomUUID();
    this.sessions.add(token);
    return token;
  }

  isValidSession(token) {
    return typeof token === "string" && this.sessions.has(token);
  }

  revoke(token) {
    this.sessions.delete(token);
  }
}

module.exports = { PairingStore };
