import { jsPDF } from "jspdf";

const MARGIN = 18;
const PAGE_WIDTH = 210;
const CONTENT_WIDTH = PAGE_WIDTH - MARGIN * 2;

function slugify(value) {
  return (value || "devplan")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

export function exportLeanCanvasPdf({
  projectTitle,
  canvas,
  pitch,
  title,
  canvasHeading,
  pitchHeading,
  emptyField,
  fieldLabels,
}) {
  const doc = new jsPDF({ unit: "mm", format: "a4" });
  let y = MARGIN;

  function ensureSpace(lines) {
    if (y + lines * 6 > 297 - MARGIN) {
      doc.addPage();
      y = MARGIN;
    }
  }

  doc.setFont("helvetica", "bold");
  doc.setFontSize(18);
  doc.text(projectTitle || title, MARGIN, y);
  y += 7;

  doc.setFont("helvetica", "normal");
  doc.setFontSize(10);
  doc.setTextColor(120);
  doc.text(title, MARGIN, y);
  doc.setTextColor(0);
  y += 10;

  doc.setFont("helvetica", "bold");
  doc.setFontSize(13);
  doc.text(canvasHeading, MARGIN, y);
  y += 8;

  Object.entries(fieldLabels).forEach(([key, label]) => {
    const items = canvas[key] || [];
    ensureSpace(2);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(11);
    doc.text(label, MARGIN, y);
    y += 6;

    doc.setFont("helvetica", "normal");
    doc.setFontSize(10);
    if (items.length === 0) {
      ensureSpace(1);
      doc.setTextColor(150);
      doc.text(emptyField, MARGIN + 4, y);
      doc.setTextColor(0);
      y += 6;
    } else {
      items.forEach((item) => {
        const lines = doc.splitTextToSize(`• ${item}`, CONTENT_WIDTH - 4);
        ensureSpace(lines.length);
        doc.text(lines, MARGIN + 4, y);
        y += lines.length * 5.5;
      });
    }
    y += 3;
  });

  if (pitch) {
    ensureSpace(3);
    y += 3;
    doc.setFont("helvetica", "bold");
    doc.setFontSize(13);
    doc.text(pitchHeading, MARGIN, y);
    y += 8;

    doc.setFont("helvetica", "normal");
    doc.setFontSize(10);
    const lines = doc.splitTextToSize(pitch, CONTENT_WIDTH);
    ensureSpace(lines.length);
    doc.text(lines, MARGIN, y);
  }

  doc.save(`${slugify(projectTitle)}-lean-canvas.pdf`);
}
