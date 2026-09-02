import { apiFetch } from "@/lib/api";
import { getToken } from "@/lib/auth";

export interface AppNotification {
  id: string;
  type: string;
  data: {
    type: string;
    title: string;
    body: string;
    action_url?: string;
  };
  read_at: string | null;
  created_at: string;
}

export async function listNotifications(): Promise<{ data: AppNotification[]; unread_count: number }> {
  return apiFetch<{ data: AppNotification[]; unread_count: number }>("/notifications", { token: getToken() });
}

export async function markNotificationRead(id: string): Promise<void> {
  await apiFetch<void>(`/notifications/${id}/read`, { method: "POST", token: getToken() });
}

export async function markAllNotificationsRead(): Promise<void> {
  await apiFetch<void>("/notifications/read-all", { method: "POST", token: getToken() });
}

export async function updateNotificationPreferences(prefs: {
  payment_reminder_emails: boolean;
  status_change_emails: boolean;
}): Promise<void> {
  await apiFetch<void>("/customer/notification-preferences", { method: "PUT", token: getToken(), body: prefs });
}
