export function countTree(node) {
  if (!node) return { files: 0, folders: 0 };
  if (node.type !== "folder") return { files: 1, folders: 0 };

  let files = 0;
  let folders = 1;
  for (const child of node.children || []) {
    const counts = countTree(child);
    files += counts.files;
    folders += counts.folders;
  }
  return { files, folders };
}
