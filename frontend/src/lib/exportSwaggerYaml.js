import { dump } from "js-yaml";

function schemaFromFields(fields) {
  if (!fields || fields.length === 0) return { type: "object" };
  return {
    type: "object",
    properties: Object.fromEntries(fields.map((f) => [f.name, { type: f.type || "string" }])),
  };
}

export function buildSwaggerDocument({ title, endpoints }) {
  const paths = {};

  for (const ep of endpoints) {
    if (!paths[ep.path]) paths[ep.path] = {};
    const method = (ep.method || "GET").toLowerCase();

    const operation = {
      summary: ep.summary || "",
      responses: {
        "200": {
          description: "OK",
          content: {
            "application/json": { schema: schemaFromFields(ep.responseFields) },
          },
        },
      },
    };

    if (["post", "put", "patch"].includes(method) && ep.requestFields?.length > 0) {
      operation.requestBody = {
        required: true,
        content: {
          "application/json": { schema: schemaFromFields(ep.requestFields) },
        },
      };
    }

    paths[ep.path][method] = operation;
  }

  return {
    openapi: "3.0.3",
    info: { title: title || "DevPlan API", version: "1.0.0" },
    paths,
  };
}

function slugify(value) {
  return (value || "devplan")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

export function exportSwaggerYaml({ title, endpoints }) {
  const doc = buildSwaggerDocument({ title, endpoints });
  const content = dump(doc, { noRefs: true, lineWidth: 100 });

  const blob = new Blob([content], { type: "application/x-yaml" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `${slugify(title)}-swagger.yaml`;
  link.click();
  URL.revokeObjectURL(url);
}
