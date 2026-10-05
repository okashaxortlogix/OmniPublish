import {
  SocialAccount,
  MediaAsset,
  PublishBatch,
  PublishJob,
  AnalyticsSummary,
  GlobalMetadata,
  PlatformOverrides,
  SupportedPlatform
} from './types';

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://127.0.0.1:8001/api/v1';

export function getStoredWorkspaceId(): string {
  if (typeof window !== 'undefined') {
    return localStorage.getItem('omnipublish_workspace_id') || 'omnipublish-studio';
  }
  return 'omnipublish-studio';
}

export function setStoredWorkspaceId(id: string) {
  if (typeof window !== 'undefined') {
    localStorage.setItem('omnipublish_workspace_id', id);
  }
}

export function getStoredAuthToken(): string | null {
  if (typeof window !== 'undefined') {
    return localStorage.getItem('omnipublish_auth_token');
  }
  return null;
}

export function setStoredAuthToken(token: string | null) {
  if (typeof window !== 'undefined') {
    if (token) {
      localStorage.setItem('omnipublish_auth_token', token);
    } else {
      localStorage.removeItem('omnipublish_auth_token');
    }
  }
}

// High-Speed In-Memory & Session Storage Stale-While-Revalidate (SWR) Cache
interface CacheEntry<T> {
  data: T;
  timestamp: number;
}
const memoryCache = new Map<string, CacheEntry<unknown>>();
const CACHE_TTL_MS = 20_000; // 20 seconds fresh TTL

export function clearApiCache(endpointPrefix?: string) {
  if (!endpointPrefix) {
    memoryCache.clear();
    if (typeof window !== 'undefined') {
      try {
        Object.keys(sessionStorage).forEach((k) => {
          if (k.startsWith('swr_cache_')) sessionStorage.removeItem(k);
        });
      } catch (e) {}
    }
    return;
  }
  for (const key of memoryCache.keys()) {
    if (key.includes(endpointPrefix)) {
      memoryCache.delete(key);
    }
  }
  if (typeof window !== 'undefined') {
    try {
      Object.keys(sessionStorage).forEach((k) => {
        if (k.includes(endpointPrefix)) sessionStorage.removeItem(k);
      });
    } catch (e) {}
  }
}

async function request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const url = `${API_BASE}${endpoint}`;
  const workspaceId = getStoredWorkspaceId();
  const token = getStoredAuthToken();
  const isGet = !options.method || options.method.toUpperCase() === 'GET';
  const cacheKey = `swr_cache_${endpoint}:${workspaceId}`;

  // Check cache for GET requests
  if (isGet) {
    const memEntry = memoryCache.get(cacheKey) as CacheEntry<T> | undefined;
    const now = Date.now();
    if (memEntry && now - memEntry.timestamp < CACHE_TTL_MS) {
      return memEntry.data;
    }

    // Try Session Storage for instant reload across tab switches
    if (!memEntry && typeof window !== 'undefined') {
      try {
        const stored = sessionStorage.getItem(cacheKey);
        if (stored) {
          const parsed = JSON.parse(stored) as CacheEntry<T>;
          if (now - parsed.timestamp < CACHE_TTL_MS * 2) {
            memoryCache.set(cacheKey, parsed as CacheEntry<unknown>);
            // Silently revalidate in background without blocking
            fetchFresh<T>(url, endpoint, workspaceId, token, options, cacheKey).catch(() => {});
            return parsed.data;
          }
        }
      } catch (e) {}
    }
  } else {
    // On mutations, clear related caches
    const rootPath = endpoint.split('?')[0].split('/')[1] || '';
    if (rootPath) {
      clearApiCache(rootPath);
    } else {
      clearApiCache();
    }
  }

  return fetchFresh<T>(url, endpoint, workspaceId, token, options, isGet ? cacheKey : null);
}

async function fetchFresh<T>(
  url: string,
  endpoint: string,
  workspaceId: string,
  token: string | null,
  options: RequestInit,
  cacheKey: string | null
): Promise<T> {
  const headers: Record<string, string> = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Workspace-Id': workspaceId,
  };

  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  // Abort controller with 8s timeout to avoid hanging indefinitely on slow internet
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 8000);

  try {
    const res = await fetch(url, {
      ...options,
      headers: {
        ...headers,
        ...options.headers,
      },
      signal: options.signal || controller.signal,
    });
    clearTimeout(timeoutId);

    const json = await res.json();
    if (!res.ok || json.success === false) {
      throw new Error(json.message || json.error?.message || 'API request failed');
    }

    const data = json.data as T;

    // Cache the fresh response if cacheable
    if (cacheKey) {
      const entry: CacheEntry<T> = { data, timestamp: Date.now() };
      memoryCache.set(cacheKey, entry as CacheEntry<unknown>);
      if (typeof window !== 'undefined') {
        try {
          sessionStorage.setItem(cacheKey, JSON.stringify(entry));
        } catch (e) {}
      }
    }

    return data;
  } catch (err) {
    clearTimeout(timeoutId);
    // If request failed/timed out, try serving stale cache if available
    if (cacheKey && typeof window !== 'undefined') {
      try {
        const stored = sessionStorage.getItem(cacheKey);
        if (stored) {
          const parsed = JSON.parse(stored) as CacheEntry<T>;
          return parsed.data;
        }
      } catch (e) {}
    }
    console.warn(`API call to ${endpoint} failed:`, err);
    throw err;
  }
}

export const api = {
  // Auth & Profile
  getMe: async () => {
    return request<{ user: { id: number; name: string; email: string }; currentWorkspace: { id: string; name: string; slug: string } }>('/auth/me');
  },

  updateProfile: async (name: string, email: string) => {
    return request<{ id: number; name: string; email: string }>('/auth/profile', {
      method: 'PUT',
      body: JSON.stringify({ name, email }),
    });
  },

  updatePassword: async (currentPassword: string, newPassword: string) => {
    return request<{ message: string }>('/auth/password', {
      method: 'PUT',
      body: JSON.stringify({ current_password: currentPassword, new_password: newPassword }),
    });
  },

  // Workspaces & Team
  getWorkspaces: async () => {
    return request<Array<{ id: string; name: string; slug: string }>>('/workspaces');
  },

  getWorkspaceMembers: async (workspaceId?: string) => {
    const ws = workspaceId || getStoredWorkspaceId();
    return request<Array<{
      id: string;
      workspace_id: string;
      user_id: number;
      role: 'OWNER' | 'ADMIN' | 'EDITOR' | 'VIEWER';
      created_at: string;
      user: { id: number; name: string; email: string };
    }>>(`/workspaces/${ws}/members`);
  },

  inviteWorkspaceMember: async (email: string, role: string, name?: string, workspaceId?: string) => {
    const ws = workspaceId || getStoredWorkspaceId();
    return request(`/workspaces/${ws}/members`, {
      method: 'POST',
      body: JSON.stringify({ email, role, name }),
    });
  },

  removeWorkspaceMember: async (memberId: string, workspaceId?: string) => {
    const ws = workspaceId || getStoredWorkspaceId();
    return request(`/workspaces/${ws}/members/${memberId}`, {
      method: 'DELETE',
    });
  },

  getAuditLogs: async (workspaceId?: string) => {
    const ws = workspaceId || getStoredWorkspaceId();
    return request<{
      data: Array<{
        id: string;
        action: string;
        resource_type: string;
        resource_id?: string;
        metadata?: Record<string, unknown>;
        created_at: string;
        user?: { id: number; name: string; email: string };
      }>;
      total: number;
    }>(`/workspaces/${ws}/audit-logs`);
  },

  getNotifications: async () => {
    return request<Array<{
      id: string;
      category: 'Publishing' | 'Account' | 'Team' | 'Security';
      title: string;
      description: string;
      timestamp: string;
      read: boolean;
      link?: string;
    }>>('/notifications');
  },

  // Social Accounts
  getSocialAccounts: async () => {
    return request<{ accounts: SocialAccount[]; capabilities: Record<string, unknown> }>('/social-accounts');
  },

  connectAccount: async (platform: string, accountName: string, accountHandle?: string) => {
    return request<SocialAccount>(`/social-accounts/connect/${platform.toLowerCase()}`, {
      method: 'POST',
      body: JSON.stringify({
        workspaceId: getStoredWorkspaceId(),
        accountName,
        accountHandle,
      }),
    });
  },

  completeOAuth: async (platform: string, payload: { idToken?: string; accessToken: string; refreshToken?: string }) => {
    return request<SocialAccount>(`/oauth/complete/${platform.toLowerCase()}`, {
      method: 'POST',
      body: JSON.stringify({
        workspaceId: getStoredWorkspaceId(),
        ...payload,
      }),
    });
  },

  getYouTubeAuthUrl: async () => {
    return request<{ redirectUrl?: string | null; configured?: boolean; message?: string }>(`/oauth/youtube/authorize?workspaceId=${getStoredWorkspaceId()}`);
  },

  getTikTokAuthUrl: async () => {
    return request<{ redirectUrl?: string | null; configured?: boolean; message?: string }>(`/oauth/tiktok/authorize?workspaceId=${getStoredWorkspaceId()}`);
  },

  getFacebookAuthUrl: async () => {
    return request<{ redirectUrl?: string | null; configured?: boolean; message?: string }>(`/oauth/facebook/authorize?workspaceId=${getStoredWorkspaceId()}`);
  },

  getLinkedInAuthUrl: async () => {
    return request<{ redirectUrl?: string | null; configured?: boolean; message?: string }>(`/oauth/linkedin/authorize?workspaceId=${getStoredWorkspaceId()}`);
  },

  getTwitterAuthUrl: async () => {
    return request<{ redirectUrl?: string | null; configured?: boolean; message?: string }>(`/oauth/twitter/authorize?workspaceId=${getStoredWorkspaceId()}`);
  },

  getPinterestAuthUrl: async () => {
    return request<{ redirectUrl?: string | null; configured?: boolean; message?: string }>(`/oauth/pinterest/authorize?workspaceId=${getStoredWorkspaceId()}`);
  },

  getSocialAccount: async (id: string) => {
    return request<{
      account: SocialAccount;
      capabilities: Record<string, unknown>;
      recentJobs: unknown[];
    }>(`/social-accounts/${id}`);
  },

  updateSocialAccount: async (
    id: string,
    data: { accountName?: string; accountHandle?: string; metadata?: Record<string, unknown> }
  ) => {
    return request<SocialAccount>(`/social-accounts/${id}`, {
      method: 'PUT',
      body: JSON.stringify(data),
    });
  },

  syncSocialAccount: async (id: string) => {
    return request<SocialAccount>(`/social-accounts/${id}/sync`, {
      method: 'POST',
    });
  },

  getSocialAccountPosts: async (id: string) => {
    return request<{ data: unknown[] }>(`/social-accounts/${id}/posts`);
  },

  disconnectAccount: async (accountId: string) => {
    return request<{ message: string }>(`/social-accounts/${accountId}/disconnect`, {
      method: 'POST',
    });
  },

  // Media Library
  getMediaAssets: async () => {
    return request<MediaAsset[]>('/media');
  },

  requestUploadAuthorization: async (filename: string, mimeType: string, sizeBytes: number) => {
    return request<{
      media_id: string;
      upload_url: string;
      method: string;
      headers: Record<string, string>;
      storage_path: string;
      expires_at: string;
      status: string;
    }>('/media/upload-authorization', {
      method: 'POST',
      body: JSON.stringify({
        workspaceId: getStoredWorkspaceId(),
        filename,
        mime_type: mimeType,
        size_bytes: sizeBytes,
      }),
    });
  },

  confirmMediaUpload: async (mediaId: string) => {
    return request<MediaAsset>('/media/confirm-upload', {
      method: 'POST',
      body: JSON.stringify({
        workspaceId: getStoredWorkspaceId(),
        media_id: mediaId,
      }),
    });
  },

  getMediaAsset: async (mediaId: string) => {
    return request<MediaAsset>(`/media/${mediaId}`);
  },

  reprocessMedia: async (mediaId: string) => {
    return request<MediaAsset>(`/media/${mediaId}/reprocess`, {
      method: 'POST',
    });
  },

  deleteMediaAsset: async (mediaId: string) => {
    return request<{ message: string }>(`/media/${mediaId}`, {
      method: 'DELETE',
    });
  },

  uploadMedia: async (title: string) => {
    return request<MediaAsset>('/media/upload', {
      method: 'POST',
      body: JSON.stringify({
        workspaceId: getStoredWorkspaceId(),
        mockTitle: title,
      }),
    });
  },

  // Publishing
  getPublishBatches: async () => {
    const res = await request<{ data: PublishBatch[] }>('/publish/batches');
    return res.data || [];
  },

  getPublishBatch: async (id: string) => {
    return request<PublishBatch>(`/publish/batches/${id}`);
  },

  createPublishBatch: async (payload: {
    mediaAssetId?: string;
    globalMetadata: GlobalMetadata;
    platformOverrides: PlatformOverrides;
    targetPlatforms: SupportedPlatform[];
    scheduledFor?: string;
  }) => {
    return request<PublishBatch>('/publish/batches', {
      method: 'POST',
      body: JSON.stringify({
        workspaceId: getStoredWorkspaceId(),
        ...payload,
      }),
    });
  },

  deletePublishBatch: async (id: string) => {
    return request<{ message: string }>(`/publish/batches/${id}`, {
      method: 'DELETE',
    });
  },

  retryPublishJob: async (jobId: string) => {
    return request<PublishJob>(`/publish/jobs/${jobId}/retry`, {
      method: 'POST',
    });
  },

  validateContent: async (payload: {
    globalMetadata: GlobalMetadata;
    platformOverrides: PlatformOverrides;
    targetPlatforms: SupportedPlatform[];
  }) => {
    return request<{ isValid: boolean; issues: Record<string, Array<{ field: string; severity: string; message: string }>> }>('/publish/validate', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  // Calendar
  getCalendar: async () => {
    return request<PublishBatch[]>('/calendar');
  },

  // Analytics
  getAnalyticsSummary: async () => {
    return request<AnalyticsSummary>('/analytics/summary');
  },
};
