"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Bell } from "lucide-react";
import { useRouter } from "@/i18n/navigation";
import { apiFetch } from "@/lib/api";
import { useProject } from "@/lib/project-context";

const POLL_INTERVAL_MS = 30000;

function messageFor(t, notification) {
  const data = notification.data;
  switch (data.type) {
    case "mention":
      return t("mention", { author: data.author_name, project: data.project_title });
    case "team_invitation":
      return t("teamInvitation", { inviter: data.inviter_name, team: data.team_name, role: data.role });
    case "deployment_finished":
      return t(data.status === "success" ? "deploymentSuccess" : "deploymentFailed", {
        project: data.project_title || `#${data.project_id}`,
        platform: data.platform,
      });
    default:
      return null;
  }
}

export function NotificationBell() {
  const t = useTranslations("Notifications");
  const router = useRouter();
  const { switchProject } = useProject();
  const [open, setOpen] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);
  const [notifications, setNotifications] = useState(null);
  const containerRef = useRef(null);

  const refreshUnreadCount = useCallback(async () => {
    try {
      const { count } = await apiFetch("/notifications/unread-count");
      setUnreadCount(count);
    } catch {
      // A failed poll shouldn't surface an error UI — it'll retry next tick.
    }
  }, []);

  useEffect(() => {
    void Promise.resolve().then(refreshUnreadCount);
    const interval = setInterval(refreshUnreadCount, POLL_INTERVAL_MS);
    return () => clearInterval(interval);
  }, [refreshUnreadCount]);

  useEffect(() => {
    function handleClickOutside(event) {
      if (containerRef.current && !containerRef.current.contains(event.target)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  async function toggleOpen() {
    const next = !open;
    setOpen(next);
    if (next) {
      const list = await apiFetch("/notifications");
      setNotifications(list);
    }
  }

  async function handleNotificationClick(notification) {
    if (!notification.read_at) {
      await apiFetch(`/notifications/${notification.id}/read`, { method: "POST" });
      setNotifications((list) => list.map((n) => (n.id === notification.id ? { ...n, read_at: new Date().toISOString() } : n)));
      setUnreadCount((c) => Math.max(0, c - 1));
    }
    setOpen(false);

    const data = notification.data;
    if (data.type === "team_invitation") {
      router.push("/teams");
    } else if (data.project_id) {
      await switchProject(data.project_id);
      router.push("/studio");
    }
  }

  async function handleMarkAllRead() {
    await apiFetch("/notifications/read-all", { method: "POST" });
    setNotifications((list) => list.map((n) => ({ ...n, read_at: n.read_at || new Date().toISOString() })));
    setUnreadCount(0);
  }

  return (
    <div className="relative" ref={containerRef}>
      <button
        onClick={toggleOpen}
        className="relative w-9 h-9 rounded-full flex items-center justify-center text-dp-muted hover:text-dp-ink hover:bg-dp-faint transition-colors"
        aria-label={t("bellLabel", { count: unreadCount })}
        aria-expanded={open}
        aria-haspopup="true"
      >
        <Bell size={16} strokeWidth={1.8} />
        {unreadCount > 0 && (
          <span className="absolute top-1 right-1 min-w-[16px] h-4 px-1 rounded-full bg-dp-accent text-[10px] leading-4 font-semibold text-white text-center">
            {unreadCount > 9 ? "9+" : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div
          role="menu"
          className="absolute right-0 mt-2 w-80 max-h-96 overflow-y-auto rounded-2xl border border-dp-border bg-dp-panel shadow-xl z-50"
        >
          <div className="flex items-center justify-between px-4 py-3 border-b border-dp-border">
            <span className="text-sm font-semibold">{t("title")}</span>
            {unreadCount > 0 && (
              <button onClick={handleMarkAllRead} className="text-xs font-medium text-dp-accent hover:underline">
                {t("markAllRead")}
              </button>
            )}
          </div>

          {notifications === null ? (
            <div className="px-4 py-6 text-center text-sm text-dp-muted">…</div>
          ) : notifications.length === 0 ? (
            <div className="px-4 py-6 text-center text-sm text-dp-muted">{t("empty")}</div>
          ) : (
            <ul>
              {notifications.map((notification) => {
                const message = messageFor(t, notification);
                if (!message) return null;
                return (
                  <li key={notification.id}>
                    <button
                      role="menuitem"
                      onClick={() => handleNotificationClick(notification)}
                      className={`w-full text-left px-4 py-3 text-sm border-b border-dp-border last:border-b-0 hover:bg-dp-faint transition-colors ${
                        notification.read_at ? "text-dp-muted" : "text-dp-ink font-medium"
                      }`}
                    >
                      {message}
                    </button>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}
