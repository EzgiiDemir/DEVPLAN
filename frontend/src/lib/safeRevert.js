// Shared core for every "go back to a previous commit" action in Studio —
// used by both the general checkpoint-history revert (CheckpointHistoryPanel,
// "User Undo") and the per-feature "Undo this AI change" action (FeatureCard,
// "AI Undo"). Both used to call `git reset --hard <hash>` directly behind a
// bare `window.confirm`, which silently discards any uncommitted work in the
// tree. Centralizing the logic here means both entry points get the same
// protections: never discard uncommitted work silently, always show what
// will be lost before acting, and always attempt to restore whatever was
// stashed afterward.

async function git(companion, localPath, command) {
  return companion.runCommand(command, localPath);
}

/**
 * Gathers what a revert to `targetHash` would do, without changing anything
 * on disk. Returns `{ dirty, diffStat }` for the caller to render in a
 * confirmation dialog before any destructive action is taken.
 */
export async function prepareRevert({ companion, localPath, targetHash }) {
  const status = await git(companion, localPath, "git status --porcelain");
  const dirty = status.exitCode === 0 && status.output.trim().length > 0;

  const diff = await git(companion, localPath, `git diff ${targetHash} HEAD --stat`);
  const diffStat = diff.exitCode === 0 ? diff.output.trim() : "";

  return { dirty, diffStat };
}

/**
 * Performs the actual revert. Must only be called after the user has
 * explicitly confirmed having seen `prepareRevert`'s output.
 *
 * If the tree is dirty, stashes (including untracked files) before the reset
 * so uncommitted work is never destroyed, then attempts to restore that
 * stash afterward so the user gets their in-progress edits back on top of
 * the reverted state. A stash-pop conflict is reported, never silently
 * discarded — the stash is left in place (`git stash list`) for the user to
 * resolve by hand.
 */
export async function performSafeRevert({ companion, localPath, targetHash, dirty, stashLabel }) {
  let stashed = false;

  if (dirty) {
    const stash = await git(companion, localPath, `git stash push -u -m "${stashLabel}"`);
    if (stash.exitCode !== 0) {
      throw new Error(stash.output || "Could not safely stash your uncommitted changes — revert cancelled.");
    }
    stashed = true;
  }

  const reset = await git(companion, localPath, `git reset --hard ${targetHash}`);
  if (reset.exitCode !== 0) {
    throw new Error(reset.output || "Revert failed.");
  }

  // Checked immediately after the reset, before any stash-pop attempt below —
  // a stash pop is *expected* to leave the tree dirty again on purpose, so
  // "partial reset" only means the reset itself didn't fully take (e.g. a
  // Windows file lock left a stale file behind), not that a pop happened.
  const postResetStatus = await git(companion, localPath, "git status --porcelain");
  const partial = postResetStatus.exitCode !== 0 || postResetStatus.output.trim().length > 0;

  let stashPopped = false;
  let stashConflict = false;
  if (stashed) {
    const pop = await git(companion, localPath, "git stash pop");
    if (pop.exitCode === 0) {
      stashPopped = true;
    } else {
      stashConflict = true;
    }
  }

  return { partial, stashed, stashPopped, stashConflict };
}
