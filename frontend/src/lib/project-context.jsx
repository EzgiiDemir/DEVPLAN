"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { apiFetch } from "./api";
import { useAuth } from "./auth-context";

const ProjectContext = createContext(null);
const ACTIVE_PROJECT_KEY = "devplan.activeProjectId";

export function ProjectProvider({ children }) {
  const { user, clearSession } = useAuth();
  const [projects, setProjects] = useState([]);
  const [project, setProject] = useState(null);
  const [loading, setLoading] = useState(true);

  const loadProjects = useCallback(async () => {
    setLoading(true);
    try {
      const list = await apiFetch("/projects");
      setProjects(list);

      if (list.length === 0) {
        setProject(null);
        localStorage.removeItem(ACTIVE_PROJECT_KEY);
        return;
      }

      const storedId = Number(localStorage.getItem(ACTIVE_PROJECT_KEY));
      const activeId = list.some((p) => p.id === storedId) ? storedId : list[0].id;
      const detail = await apiFetch(`/projects/${activeId}`);
      setProject(detail);
      localStorage.setItem(ACTIVE_PROJECT_KEY, String(activeId));
    } catch (err) {
      // Session expired (e.g. the account behind it no longer exists) —
      // drop back to logged-out state instead of leaving a dead session
      // hanging around, so /login shows up instead of a stuck screen.
      if (err.status === 401) clearSession();
      setProjects([]);
      setProject(null);
    } finally {
      setLoading(false);
    }
  }, [clearSession]);

  useEffect(() => {
    // Deferred to a microtask so no branch calls setState synchronously
    // within the effect's own call stack.
    if (user) {
      void Promise.resolve().then(() => loadProjects());
    } else {
      void Promise.resolve().then(() => {
        setProjects([]);
        setProject(null);
        setLoading(false);
      });
    }
  }, [user, loadProjects]);

  async function createProject(title, description) {
    try {
      const created = await apiFetch("/projects", {
        method: "POST",
        body: JSON.stringify({ title, description }),
      });
      setProjects((list) => [created, ...list]);
      setProject(created);
      localStorage.setItem(ACTIVE_PROJECT_KEY, String(created.id));
      return created;
    } catch (err) {
      if (err.status === 401) clearSession();
      throw err;
    }
  }

  async function switchProject(projectId) {
    if (projectId === project?.id) return project;
    try {
      const detail = await apiFetch(`/projects/${projectId}`);
      setProject(detail);
      localStorage.setItem(ACTIVE_PROJECT_KEY, String(projectId));
      return detail;
    } catch (err) {
      if (err.status === 401) clearSession();
      throw err;
    }
  }

  async function updateProject(projectId, data) {
    try {
      const updated = await apiFetch(`/projects/${projectId}`, {
        method: "PUT",
        body: JSON.stringify(data),
      });
      setProject((p) => (p && p.id === projectId ? { ...p, ...updated } : p));
      setProjects((list) => list.map((p) => (p.id === projectId ? { ...p, ...updated } : p)));
      return updated;
    } catch (err) {
      if (err.status === 401) clearSession();
      throw err;
    }
  }

  async function updateModuleStatus(moduleId, status) {
    try {
      const updated = await apiFetch(`/modules/${moduleId}`, {
        method: "PUT",
        body: JSON.stringify({ status }),
      });
      setProject((p) => ({
        ...p,
        modules: p.modules.map((m) => (m.id === moduleId ? { ...m, status: updated.status } : m)),
      }));
      return updated;
    } catch (err) {
      if (err.status === 401) clearSession();
      throw err;
    }
  }

  const myRole = project?.my_role ?? null;
  // Viewers are read-only by design (Phase 10) — everything that creates,
  // mutates, or triggers AI/deploy actions gates on this.
  const canAct = myRole === "developer" || myRole === "admin" || myRole === "owner";
  const canManage = myRole === "admin" || myRole === "owner";

  return (
    <ProjectContext.Provider
      value={{
        project,
        projects,
        loading,
        myRole,
        canAct,
        canManage,
        createProject,
        switchProject,
        updateProject,
        updateModuleStatus,
        refresh: loadProjects,
      }}
    >
      {children}
    </ProjectContext.Provider>
  );
}

export function useProject() {
  const ctx = useContext(ProjectContext);
  if (!ctx) throw new Error("useProject must be used within ProjectProvider");
  return ctx;
}
