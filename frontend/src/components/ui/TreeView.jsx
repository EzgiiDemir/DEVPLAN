"use client";

import { useState } from "react";
import { ChevronDown, ChevronRight, Folder, File } from "lucide-react";

function TreeNode({ node, depth }) {
  const [open, setOpen] = useState(depth < 1);

  if (node.type !== "folder") {
    return (
      <div
        className="flex items-center gap-1.5 py-1 text-sm text-dp-muted-2"
        style={{ paddingLeft: `${depth * 16 + 22}px` }}
      >
        <File size={13} className="flex-shrink-0" />
        {node.name}
      </div>
    );
  }

  return (
    <div>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="flex items-center gap-1.5 py-1 text-sm font-medium w-full text-left hover:text-dp-accent-strong transition-colors"
        style={{ paddingLeft: `${depth * 16}px` }}
      >
        {open ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
        <Folder size={13} className="text-dp-accent flex-shrink-0" />
        {node.name}
      </button>
      {open && (node.children || []).map((child, i) => <TreeNode key={child.name + i} node={child} depth={depth + 1} />)}
    </div>
  );
}

export function TreeView({ tree }) {
  if (!tree) return null;
  return (
    <div className="font-mono">
      <TreeNode node={tree} depth={0} />
    </div>
  );
}
