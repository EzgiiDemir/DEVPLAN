import { jsPDF } from "jspdf";

function slugify(value) {
  return (value || "devplan")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

function download(filename, content, mime) {
  const blob = new Blob([content], { type: mime });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(url);
}

export function exportRoadmapJson(summary) {
  download(`${slugify(summary.projectName)}-roadmap.json`, JSON.stringify(summary, null, 2), "application/json");
}

export function exportRoadmapMarkdown(summary, labels) {
  const lines = [
    `# ${summary.projectName}`,
    "",
    `## ${labels.techStack}`,
    `- ${labels.frontend}: ${summary.frontend || "-"}`,
    `- ${labels.backend}: ${summary.backend || "-"}`,
    `- ${labels.database}: ${summary.database || "-"}`,
    `- ${labels.hosting}: ${summary.hosting || "-"}`,
    "",
    `## ${labels.progress}`,
    `- ${labels.completedModules}: ${summary.completedModules} / ${summary.totalModules}`,
    `- ${labels.remainingTasks}: ${summary.remainingTasks}`,
    `- ${labels.estimatedTime}: ${summary.estimatedTime}`,
    `- ${labels.sprintProgress}: ${summary.sprintProgress.done} / ${summary.sprintProgress.total}`,
    "",
  ];
  download(`${slugify(summary.projectName)}-roadmap.md`, lines.join("\n"), "text/markdown");
}

export function exportRoadmapPdf(summary, labels) {
  const doc = new jsPDF({ unit: "mm", format: "a4" });
  const margin = 18;
  let y = margin;

  doc.setFont("helvetica", "bold");
  doc.setFontSize(20);
  doc.text(summary.projectName, margin, y);
  y += 12;

  const rows = [
    [labels.frontend, summary.frontend || "-"],
    [labels.backend, summary.backend || "-"],
    [labels.database, summary.database || "-"],
    [labels.hosting, summary.hosting || "-"],
    [labels.completedModules, `${summary.completedModules} / ${summary.totalModules}`],
    [labels.remainingTasks, String(summary.remainingTasks)],
    [labels.estimatedTime, summary.estimatedTime],
    [labels.sprintProgress, `${summary.sprintProgress.done} / ${summary.sprintProgress.total}`],
  ];

  doc.setFontSize(11);
  for (const [label, value] of rows) {
    doc.setFont("helvetica", "bold");
    doc.text(`${label}:`, margin, y);
    doc.setFont("helvetica", "normal");
    doc.text(String(value), margin + 55, y);
    y += 8;
  }

  doc.save(`${slugify(summary.projectName)}-roadmap.pdf`);
}
