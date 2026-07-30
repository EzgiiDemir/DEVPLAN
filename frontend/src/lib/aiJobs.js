import { apiFetch } from "@/lib/api";

/**
 * Polls GET /ai-jobs/{id} until the background AI job (Subsystem 3 — Queue
 * System) resolves. Every endpoint that used to make the caller wait for an
 * LLM call now returns `{job_id}` immediately instead; this is the one place
 * that waiting happens, client-side, so a slow/stuck AI call can no longer
 * hold an HTTP request (and therefore a PHP worker) open indefinitely.
 */
export async function pollAiJob(jobId, { intervalMs = 1500, timeoutMs = 300000 } = {}) {
  const start = Date.now();

  for (;;) {
    const job = await apiFetch(`/ai-jobs/${jobId}`);

    if (job.status === "succeeded") return job.result;
    if (job.status === "failed") throw new Error(job.error || "The AI request failed.");
    if (job.status === "cancelled") throw new Error("The AI request was cancelled.");

    if (Date.now() - start > timeoutMs) {
      throw new Error("Timed out waiting for the AI request to finish.");
    }

    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }
}
