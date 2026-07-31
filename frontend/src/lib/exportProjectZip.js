const API_BASE_URL = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8010/api/v1";

/**
 * Unlike the other export* helpers (which build a zip client-side with
 * JSZip from data already in memory), these download a zip the backend
 * already assembled, so they need a real fetch + blob instead of a JSON
 * apiFetch call.
 */
async function downloadZip(path, fallbackFilename) {
  const res = await fetch(`${API_BASE_URL}${path}`, {
    credentials: "include",
    headers: { Accept: "application/zip" },
  });

  if (!res.ok) {
    throw new Error(`Export failed: ${res.status}`);
  }

  const blob = await res.blob();
  const disposition = res.headers.get("content-disposition") || "";
  const match = disposition.match(/filename="?([^"]+)"?/);
  const filename = match ? match[1] : fallbackFilename;

  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export async function downloadProjectExport(projectId) {
  await downloadZip(`/projects/${projectId}/export`, "devplan-export.zip");
}

export async function downloadAccountExport() {
  await downloadZip("/account/export", "devplan-account-export.zip");
}
