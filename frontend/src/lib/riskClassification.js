// Companion's allowlist (mirrored in companion/src/commands.js) is the real
// security boundary — this only labels how risky an already-allowed command
// is, so the UI can ask for real confirmation before dangerous ones run and
// so every execution gets a meaningful risk_level in the audit log.
const DANGEROUS_PATTERNS = [
  /\bgit\s+push\b.*(--force|-f\b)/i,
  /\bgit\s+reset\b.*--hard\b/i,
  /\bgit\s+clean\b.*(-f|-d)/i,
  /\bnpm\s+publish\b/i,
  /\bdocker\s+(rm|rmi|system\s+prune)\b/i,
  /\byarn\s+publish\b/i,
  /\bcomposer\s+.*--no-interaction\b.*(remove|require)\b/i,
];

const SENSITIVE_PATTERNS = [
  /\bgit\s+(push|checkout|branch\s+-d|rebase)\b/i,
  /\bnpm\s+(uninstall|remove)\b/i,
  /\bdocker\s+(stop|kill)\b/i,
];

export function classifyCommand(command) {
  const trimmed = String(command || "").trim();
  if (DANGEROUS_PATTERNS.some((p) => p.test(trimmed))) return "dangerous";
  if (SENSITIVE_PATTERNS.some((p) => p.test(trimmed))) return "sensitive";
  return "safe";
}
