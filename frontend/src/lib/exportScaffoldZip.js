import JSZip from "jszip";

function addNode(zipFolder, node) {
  if (node.type === "folder") {
    const folder = zipFolder.folder(node.name);
    for (const child of node.children || []) {
      addNode(folder, child);
    }
  } else {
    zipFolder.file(node.name, `// ${node.name}\n`);
  }
}

function slugify(value) {
  return (value || "devplan")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

export async function exportScaffoldZip({ title, tree }) {
  const zip = new JSZip();
  for (const child of tree.children || []) {
    addNode(zip, child);
  }

  const blob = await zip.generateAsync({ type: "blob" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `${slugify(title)}-scaffold.zip`;
  link.click();
  URL.revokeObjectURL(url);
}
