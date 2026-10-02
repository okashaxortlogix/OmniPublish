export type SupportedPlatform =
  | 'YOUTUBE'
  | 'TIKTOK'
  | 'INSTAGRAM'
  | 'FACEBOOK'
  | 'LINKEDIN'
  | 'TWITTER'
  | 'X'
  | 'PINTEREST';

export interface SocialAccount {
  id: string;
  workspace_id: string;
  platform: SupportedPlatform;
  platform_account_id: string;
  account_name: string;
  account_handle?: string;
  avatar_url?: string;
  status: 'ACTIVE' | 'EXPIRED' | 'REVOKED' | 'ACTION_REQUIRED';
  scopes: string[];
  token_expires_at?: string;
  metadata?: Record<string, unknown>;
}

export interface MediaAsset {
  id: string;
  workspace_id: string;
  filename: string;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  storage_path: string;
  thumbnail_path?: string;
  thumbnail_url?: string;
  url?: string;
  duration_seconds?: number;
  width?: number;
  height?: number;
  aspect_ratio?: string;
  codec?: string;
  status: 'UPLOADING' | 'PROCESSING' | 'READY' | 'FAILED' | 'DELETED';
}

export interface GlobalMetadata {
  title: string;
  description: string;
  caption?: string;
  hashtags: string[];
  tags: string[];
  visibility: 'PUBLIC' | 'UNLISTED' | 'PRIVATE';
}

export interface PlatformOverrides {
  youtube?: {
    title?: string;
    description?: string;
    categoryId?: string;
    madeForKids?: boolean;
    privacyStatus?: 'public' | 'unlisted' | 'private';
    tags?: string[];
  };
  tiktok?: {
    caption?: string;
    privacyLevel?: 'PUBLIC_TO_EVERYONE' | 'MUTUAL_FOLLOW_FRIENDS' | 'FOLLOWER_OF_CREATOR' | 'SELF_ONLY';
    disableComment?: boolean;
    disableDuet?: boolean;
    disableStitch?: boolean;
  };
  instagram?: {
    caption?: string;
    shareToFeed?: boolean;
    coverUrl?: string;
  };
}

export interface PublishJob {
  id: string;
  publish_batch_id?: string;
  workspace_id?: string;
  social_account_id?: string;
  platform: SupportedPlatform;
  status:
    | 'CREATED'
    | 'QUEUED'
    | 'UPLOADING'
    | 'PROCESSING'
    | 'PUBLISHING'
    | 'PUBLISHED'
    | 'FAILED'
    | 'AUTH_REQUIRED'
    | 'RATE_LIMITED'
    | 'RETRYING';
  attempts_count?: number;
  attemptCount?: number;
  max_attempts?: number;
  remote_post_id?: string;
  remotePostId?: string;
  remote_post_url?: string;
  publishedUrl?: string;
  publishedAt?: string;
  failure_category?: string;
  user_facing_error_message?: string;
  errorMessage?: string;
  internal_error_details?: string;
  social_account?: SocialAccount;
  created_at?: string;
  createdAt?: string;
  updated_at?: string;
}

export interface PublishBatch {
  id: string;
  workspace_id?: string;
  content_item_id?: string;
  status:
    | 'DRAFT'
    | 'VALIDATING'
    | 'QUEUED'
    | 'RUNNING'
    | 'PROCESSING'
    | 'COMPLETED'
    | 'PUBLISHED'
    | 'PARTIAL_SUCCESS'
    | 'FAILED'
    | 'CANCELLED';
  scheduled_for?: string;
  created_at?: string;
  createdAt?: string;
  publish_jobs?: PublishJob[];
  jobs?: PublishJob[];
  content_item?: {
    id: string;
    global_metadata: GlobalMetadata;
    platform_overrides: PlatformOverrides;
    target_platforms: SupportedPlatform[];
    media_asset?: MediaAsset;
  };
}

export interface AnalyticsSummary {
  totalPosts: number;
  activeChannels: number;
  successRate: number;
  failedCount: number;
  platformBreakdown: Record<string, { total: number; published: number; successRate: number }>;
}
