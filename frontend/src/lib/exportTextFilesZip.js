import JSZip from "jszip";

function slugify(value) {
  return (value || "devplan")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

export async function exportTextFilesZip({ title, suffix, files }) {
  const zip = new JSZip();
  for (const file of files) {
    zip.file(file.name, file.content);
  }

  const blob = await zip.generateAsync({ type: "blob" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `${slugify(title)}-${suffix}.zip`;
  link.click();
  URL.revokeObjectURL(url);
}
