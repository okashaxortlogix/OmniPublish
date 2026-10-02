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

async function request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
  const url = `${API_BASE}${endpoint}`;
  try {
    const res = await fetch(url, {
      ...options,
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-Workspace-Id': 'omnipublish-studio',
        ...options.headers,
      },
      cache: 'no-store',
    });

    const json = await res.json();
    if (!res.ok || json.success === false) {
      throw new Error(json.message || json.error?.message || 'API request failed');
    }
    return json.data as T;
  } catch (err) {
    console.warn(`API call to ${endpoint} failed, falling back to local mock data:`, err);
    throw err;
  }
}

export const api = {
  // Social Accounts
  getSocialAccounts: async () => {
    return request<{ accounts: SocialAccount[]; capabilities: Record<string, unknown> }>('/social-accounts');
  },

  connectAccount: async (platform: string, accountName: string, accountHandle?: string) => {
    return request<SocialAccount>(`/social-accounts/connect/${platform.toLowerCase()}`, {
      method: 'POST',
      body: JSON.stringify({
        workspaceId: 'omnipublish-studio',
        accountName,
        accountHandle,
      }),
    });
  },

  completeOAuth: async (platform: string, payload: { idToken?: string; accessToken: string; refreshToken?: string }) => {
    return request<SocialAccount>(`/oauth/complete/${platform.toLowerCase()}`, {
      method: 'POST',
      body: JSON.stringify({
        workspaceId: 'omnipublish-studio',
        ...payload,
      }),
    });
  },

  getYouTubeAuthUrl: async () => {
    return request<{ redirectUrl: string }>('/oauth/youtube/authorize?workspaceId=omnipublish-studio');
  },

  getTikTokAuthUrl: async () => {
    return request<{ redirectUrl: string }>('/oauth/tiktok/authorize?workspaceId=omnipublish-studio');
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

  uploadMedia: async (title: string) => {
    return request<MediaAsset>('/media/upload', {
      method: 'POST',
      body: JSON.stringify({
        workspaceId: 'omnipublish-studio',
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
        workspaceId: 'omnipublish-studio',
        ...payload,
      }),
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
