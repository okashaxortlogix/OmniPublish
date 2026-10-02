// ==========================================
// OmniPublish Core Domain Type Definitions
// ==========================================

export type UUID = string;
export type ISODateString = string;

// 1. Roles & Permissions
export type WorkspaceRole = 'OWNER' | 'ADMIN' | 'EDITOR' | 'VIEWER';

export interface User {
  id: UUID;
  email: string;
  fullName: string;
  avatarUrl?: string;
  isEmailVerified: boolean;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

export interface Workspace {
  id: UUID;
  name: string;
  slug: string;
  ownerId: UUID;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

export interface Membership {
  id: UUID;
  workspaceId: UUID;
  userId: UUID;
  role: WorkspaceRole;
  createdAt: ISODateString;
}

// 2. Supported Platforms & Social Accounts
export type SupportedPlatform =
  | 'YOUTUBE'
  | 'TIKTOK'
  | 'INSTAGRAM'
  | 'FACEBOOK'
  | 'LINKEDIN'
  | 'TWITTER'
  | 'PINTEREST';

export type AccountConnectionStatus =
  | 'ACTIVE'
  | 'EXPIRED'
  | 'REVOKED'
  | 'ACTION_REQUIRED'
  | 'ERROR';

export interface SocialAccount {
  id: UUID;
  workspaceId: UUID;
  platform: SupportedPlatform;
  platformAccountId: string;
  accountName: string;
  accountHandle: string;
  avatarUrl?: string;
  status: AccountConnectionStatus;
  scopes: string[];
  tokenExpiresAt?: ISODateString;
  metadata?: Record<string, unknown>;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

// 3. Media Assets
export type MediaProcessingStatus =
  | 'UPLOADING'
  | 'PROCESSING'
  | 'READY'
  | 'FAILED'
  | 'DELETED';

export interface MediaAsset {
  id: UUID;
  workspaceId: UUID;
  filename: string;
  originalName: string;
  mimeType: string;
  sizeBytes: number;
  storageKey: string;
  storageUrl?: string;
  thumbnailUrl?: string;
  durationSeconds?: number;
  width?: number;
  height?: number;
  aspectRatio?: string;
  codec?: string;
  status: MediaProcessingStatus;
  errorMessage?: string;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

// 4. Content Items & Overrides
export interface GlobalContentMetadata {
  title: string;
  description: string;
  caption?: string;
  hashtags: string[];
  tags: string[];
  thumbnailUrl?: string;
  visibility: 'PUBLIC' | 'UNLISTED' | 'PRIVATE';
}

export interface YouTubeSpecificOverrides {
  categoryId?: string;
  madeForKids: boolean;
  privacyStatus: 'public' | 'unlisted' | 'private';
  tags?: string[];
  publishAt?: ISODateString;
}

export interface TikTokSpecificOverrides {
  privacyLevel: 'PUBLIC_TO_EVERYONE' | 'MUTUAL_FOLLOW_FRIENDS' | 'FOLLOWER_OF_CREATOR' | 'SELF_ONLY';
  disableComment?: boolean;
  disableDuet?: boolean;
  disableStitch?: boolean;
  brandContentToggle?: boolean;
  brandOrganicToggle?: boolean;
}

export interface InstagramSpecificOverrides {
  shareToFeed: boolean;
  coverUrl?: string;
  thumbOffsetMs?: number;
}

export interface PlatformSpecificOverrides {
  youtube?: YouTubeSpecificOverrides;
  tiktok?: TikTokSpecificOverrides;
  instagram?: InstagramSpecificOverrides;
}

export interface ContentItem {
  id: UUID;
  workspaceId: UUID;
  mediaAssetId: UUID;
  globalMetadata: GlobalContentMetadata;
  platformOverrides: PlatformSpecificOverrides;
  targetPlatforms: SupportedPlatform[];
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

// 5. Publishing Orchestration & State Machine
export type PublishBatchStatus =
  | 'DRAFT'
  | 'VALIDATING'
  | 'QUEUED'
  | 'RUNNING'
  | 'COMPLETED'
  | 'PARTIAL_SUCCESS'
  | 'FAILED'
  | 'CANCELLED';

export type PublishJobStatus =
  | 'CREATED'
  | 'QUEUED'
  | 'UPLOADING'
  | 'PROCESSING'
  | 'PUBLISHED'
  | 'FAILED'
  | 'AUTH_REQUIRED'
  | 'RATE_LIMITED'
  | 'CANCELLED'
  | 'RETRYING';

export type FailureCategory =
  | 'AUTH_REQUIRED'
  | 'INVALID_MEDIA'
  | 'INVALID_METADATA'
  | 'PERMISSION_DENIED'
  | 'RATE_LIMITED'
  | 'QUOTA_EXCEEDED'
  | 'PROVIDER_ERROR'
  | 'NETWORK_ERROR'
  | 'TIMEOUT'
  | 'UNSUPPORTED_OPERATION'
  | 'UNKNOWN';

export interface PublishBatch {
  id: UUID;
  workspaceId: UUID;
  contentItemId: UUID;
  status: PublishBatchStatus;
  scheduledFor?: ISODateString;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

export interface PublishJob {
  id: UUID;
  batchId: UUID;
  workspaceId: UUID;
  socialAccountId: UUID;
  platform: SupportedPlatform;
  status: PublishJobStatus;
  attemptsCount: number;
  maxAttempts: number;
  remotePostId?: string;
  remotePostUrl?: string;
  failureCategory?: FailureCategory;
  userFacingErrorMessage?: string;
  internalErrorDetails?: string;
  lastAttemptAt?: ISODateString;
  createdAt: ISODateString;
  updatedAt: ISODateString;
}

// 6. Platform Capability Specification
export interface PlatformConstraintLimits {
  maxTitleLength?: number;
  maxDescriptionLength?: number;
  maxCaptionLength?: number;
  maxHashtagsCount?: number;
  maxTagsLength?: number;
  maxVideoDurationSeconds: number;
  minVideoDurationSeconds: number;
  maxFileSizeMB: number;
  supportedAspectRatios: string[];
}

export interface PlatformCapabilities {
  platform: SupportedPlatform;
  supportsTitle: boolean;
  supportsDescription: boolean;
  supportsCaption: boolean;
  supportsTags: boolean;
  supportsHashtags: boolean;
  supportsCustomThumbnail: boolean;
  supportsNativeScheduling: boolean;
  supportsAnalytics: boolean;
  requiresCategory: boolean;
  limits: PlatformConstraintLimits;
}

// 7. Audit & Telemetry
export type AuditAction =
  | 'USER_REGISTERED'
  | 'USER_LOGGED_IN'
  | 'ACCOUNT_CONNECTED'
  | 'ACCOUNT_DISCONNECTED'
  | 'MEDIA_UPLOADED'
  | 'PUBLISH_BATCH_CREATED'
  | 'PUBLISH_JOB_DISPATCHED'
  | 'PUBLISH_JOB_SUCCEEDED'
  | 'PUBLISH_JOB_FAILED'
  | 'PUBLISH_JOB_RETRIED'
  | 'SCHEDULE_CREATED'
  | 'SCHEDULE_CANCELLED';

export interface AuditEvent {
  id: UUID;
  workspaceId: UUID;
  userId?: UUID;
  action: AuditAction;
  resourceType: string;
  resourceId: string;
  metadata?: Record<string, unknown>;
  ipAddress?: string;
  userAgent?: string;
  createdAt: ISODateString;
}

// 8. Standard API Response
export interface ApiResponse<T = unknown> {
  success: boolean;
  data?: T;
  error?: {
    code: string;
    message: string;
    requestId: string;
    details?: unknown;
  };
}
