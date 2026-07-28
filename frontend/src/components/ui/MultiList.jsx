"use client";

import { useState } from "react";
import { GripVertical, Plus, X } from "lucide-react";
import { TinyBtn } from "./Buttons";

export function MultiList({ label, Icon, items, setItems, placeholder }) {
  const [draft, setDraft] = useState("");
  const [dragIndex, setDragIndex] = useState(null);
  const [overIndex, setOverIndex] = useState(null);

  function reorder(from, to) {
    if (from === to) return;
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    setItems(next);
  }

  return (
    <div className="bg-dp-panel rounded-2xl border border-dp-border p-4 shadow-[0_2px_10px_rgba(0,0,0,0.02)] h-full flex flex-col">
      {label && (
        <div className="flex items-center gap-1.5 text-[11px] font-semibold text-dp-muted mb-3 uppercase tracking-wider">
          {Icon && <Icon size={13} className="text-dp-accent-strong" />}
          {label}
        </div>
      )}
      <div className="flex-1">
        {items.map((v, i) => (
          <div
            key={i}
            draggable
            onDragStart={() => setDragIndex(i)}
            onDragEnter={() => setOverIndex(i)}
            onDragEnd={() => {
              setDragIndex(null);
              setOverIndex(null);
            }}
            onDragOver={(e) => e.preventDefault()}
            onDrop={(e) => {
              e.preventDefault();
              if (dragIndex !== null) reorder(dragIndex, i);
              setDragIndex(null);
              setOverIndex(null);
            }}
            className={`group flex justify-between items-center gap-1.5 text-sm bg-dp-faint rounded-lg px-2 py-2 mb-1.5 transition-colors ${
              overIndex === i && dragIndex !== null && dragIndex !== i ? "ring-2 ring-dp-accent/50" : ""
            } ${dragIndex === i ? "opacity-40" : ""}`}
          >
            <GripVertical size={13} className="text-dp-muted cursor-grab active:cursor-grabbing flex-shrink-0" />
            <span className="flex-1">{v}</span>
            <button
              type="button"
              onClick={() => setItems(items.filter((_, idx) => idx !== i))}
              className="cursor-pointer text-dp-muted hover:text-red-500 flex-shrink-0"
            >
              <X size={13} />
            </button>
          </div>
        ))}
      </div>
      <div className="flex gap-1.5 mt-1.5">
        <input
          placeholder={placeholder}
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter" && draft.trim()) {
              e.preventDefault();
              setItems([...items, draft]);
              setDraft("");
            }
          }}
          className="flex-1 min-w-0 rounded-lg border border-dp-border bg-dp-faint focus:bg-dp-panel focus:border-dp-accent px-3 py-2 text-sm outline-none transition-colors"
        />
        <TinyBtn
          onClick={() => {
            if (draft.trim()) {
              setItems([...items, draft]);
              setDraft("");
            }
          }}
        >
          <Plus size={13} />
        </TinyBtn>
      </div>
    </div>
  );
}
