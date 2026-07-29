# DevPlan Companion

A small local desktop app that lets **DevPlan Studio** (running in your browser)
do things a website normally can't: check what's installed on your computer,
create a real project folder, and run real terminal commands — on *your*
machine, not a cloud sandbox.

## Running it

```bash
npm install
npm start
```

A window titled "DevPlan Companion" opens showing a 6-digit pairing code.
Leave it running, open DevPlan Studio in your browser, go to **Setup Mode**,
and enter that code once to link the browser tab to this app.

## How it works

- The app starts a local HTTP server on `http://127.0.0.1:58347` — nothing
  leaves your machine, and nothing outside your machine can reach it.
- **CORS is locked** to DevPlan's own origin (`http://localhost:3000` in dev).
  No other website can call this server, even if it tried.
- **Pairing is required** for anything sensitive. The 6-digit code shown in
  the app window must be entered in DevPlan Studio before it can check your
  environment, create folders, or run commands. Never enter this code
  anywhere except DevPlan itself.
- **Command execution is allowlisted.** Only `npm`, `npx`, `pnpm`, `yarn`,
  `git`, `composer`, `pip`/`pip3`, `python`/`python3`, `php`, `dotnet`, `go`,
  `cargo`, `flutter`, and `docker` commands can run — anything else is
  rejected before it ever reaches your shell. Commands always run inside a
  project folder the companion itself created; they never run at the root of
  your drive or in an arbitrary path.

## What it can't do (on purpose)

It can't run arbitrary shell commands (`rm -rf`, `del`, etc. are rejected),
can't write outside the project folder it created, and can't be reached by
any website except DevPlan's own frontend.

## Development

`test-server-standalone.js` runs just the HTTP agent (no window) — useful for
testing the API directly without a full Electron GUI session:

```bash
node test-server-standalone.js
```
