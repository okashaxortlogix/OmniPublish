'use client';

import React, { useEffect, useState } from 'react';
import {
  Share2,
  CheckCircle2,
  AlertTriangle,
  Plus,
  Shield,
  Trash2,
  RefreshCw,
  ExternalLink,
  Users,
  Video,
  Eye,
  Check,
  Copy,
  Info,
  Sparkles,
  Lock,
  ArrowRight,
  SlidersHorizontal,
  Flame,
  Globe
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';
import { SocialAccount, SupportedPlatform } from '@/lib/types';
import {
  isFirebaseConfigured,
  getFirebaseAuth,
  googleProvider,
  facebookProvider
} from '@/lib/firebase';
import { signInWithPopup, GoogleAuthProvider, FacebookAuthProvider } from 'firebase/auth';

interface PlatformCardInfo {
  key: SupportedPlatform;
  name: string;
  category: string;
  iconBg: string;
  iconText: string;
  badgeColor: string;
  scopes: string[];
  description: string;
  oauthProvider: 'firebase-google' | 'firebase-meta' | 'tiktok-pkce' | 'custom';
}

const PLATFORMS: PlatformCardInfo[] = [
  {
    key: 'YOUTUBE',
    name: 'YouTube',
    category: 'Channel & Shorts',
    iconBg: 'bg-[#FF0000]',
    iconText: 'YT',
    badgeColor: 'text-red-600 bg-red-50 border-red-200',
    scopes: ['youtube.upload', 'youtube.readonly', 'userinfo.profile'],
    description: 'Direct publishing to YouTube Shorts and Video Feed with live subscriber analytics.',
    oauthProvider: 'firebase-google',
  },
  {
    key: 'INSTAGRAM',
    name: 'Instagram',
    category: 'Reels & Media',
    iconBg: 'bg-gradient-to-tr from-yellow-500 via-pink-600 to-purple-700',
    iconText: 'IG',
    badgeColor: 'text-pink-600 bg-pink-50 border-pink-200',
    scopes: ['instagram_content_publish', 'instagram_basic', 'pages_show_list'],
    description: 'Instagram Professional (Creator/Business) auto-publishing via Meta Graph API.',
    oauthProvider: 'firebase-meta',
  },
  {
    key: 'TIKTOK',
    name: 'TikTok',
    category: 'Creator Profile',
    iconBg: 'bg-black',
    iconText: 'TT',
    badgeColor: 'text-slate-900 bg-slate-100 border-slate-300',
    scopes: ['video.publish', 'user.info.basic'],
    description: 'High-speed video distribution via official TikTok Content Posting API v2 with PKCE.',
    oauthProvider: 'tiktok-pkce',
  },
  {
    key: 'FACEBOOK',
    name: 'Facebook',
    category: 'Pages & Reels',
    iconBg: 'bg-[#1877F2]',
    iconText: 'FB',
    badgeColor: 'text-blue-600 bg-blue-50 border-blue-200',
    scopes: ['pages_manage_posts', 'pages_read_engagement'],
    description: 'Publish cross-platform Reels and video updates to your verified Facebook Pages.',
    oauthProvider: 'custom',
  },
  {
    key: 'LINKEDIN',
    name: 'LinkedIn',
    category: 'Company & Profile',
    iconBg: 'bg-[#0A66C2]',
    iconText: 'IN',
    badgeColor: 'text-sky-700 bg-sky-50 border-sky-200',
    scopes: ['w_member_social', 'r_liteprofile'],
    description: 'Professional B2B video and thought-leadership publishing with native scheduling.',
    oauthProvider: 'custom',
  },
  {
    key: 'TWITTER',
    name: 'X (Twitter)',
    category: 'Microblogging & Video',
    iconBg: 'bg-slate-950',
    iconText: 'X',
    badgeColor: 'text-slate-800 bg-slate-100 border-slate-300',
    scopes: ['tweet.write', 'tweet.read'],
    description: 'Instant video tweet deployment with real-time impression and engagement metrics.',
    oauthProvider: 'custom',
  },
  {
    key: 'PINTEREST',
    name: 'Pinterest',
    category: 'Idea Pins & Video',
    iconBg: 'bg-[#E60023]',
    iconText: 'PIN',
    badgeColor: 'text-red-700 bg-red-50 border-red-200',
    scopes: ['pins:write', 'boards:read'],
    description: 'Turn short-form video into high-converting Idea Pins and drive referral traffic.',
    oauthProvider: 'custom',
  },
];

function formatMetric(val: unknown): string {
  if (typeof val !== 'number') {
    const num = Number(val);
    if (isNaN(num)) return '0';
    val = num;
  }
  const n = val as number;
  if (n >= 1_000_000) {
    return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
  }
  if (n >= 1_000) {
    return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
  }
  return n.toLocaleString();
}

function formatRelativeDays(expiresAt?: string): string {
  if (!expiresAt) return 'Active session';
  const diffMs = new Date(expiresAt).getTime() - Date.now();
  if (diffMs <= 0) return 'Expired';
  const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
  if (diffHours < 24) return `Expires in ${diffHours}h`;
  const diffDays = Math.floor(diffHours / 24);
  return `Expires in ${diffDays}d`;
}

export default function ConnectionsPage() {
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [connectingPlatform, setConnectingPlatform] = useState<SupportedPlatform | null>(null);
  const [loading, setLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successBanner, setSuccessBanner] = useState<string | null>(null);
  const [showFirebaseModal, setShowFirebaseModal] = useState(false);
  const [copiedEnv, setCopiedEnv] = useState(false);

  useEffect(() => {
    loadAccounts();

    // Check query params on mount for OAuth callbacks or errors
    if (typeof window !== 'undefined') {
      const params = new URLSearchParams(window.location.search);
      const connected = params.get('connected');
      const err = params.get('error');

      if (connected === 'TIKTOK' && params.get('success') === 'true') {
        setSuccessBanner('TikTok Creator account linked successfully with live video permissions!');
        // Clean URL without reload
        window.history.replaceState({}, document.title, window.location.pathname);
      } else if (err) {
        setErrorMessage(decodeURIComponent(err));
        window.history.replaceState({}, document.title, window.location.pathname);
      }
    }
  }, []);

  const loadAccounts = async () => {
    try {
      setLoading(true);
      const res = await api.getSocialAccounts();
      setAccounts(res.accounts || []);
    } catch (err) {
      console.error('Failed to load accounts', err);
    } finally {
      setLoading(false);
    }
  };

  const handleConnect = async (platform: SupportedPlatform) => {
    setErrorMessage(null);
    setSuccessBanner(null);

    // 1. YouTube Flow (Google OAuth via Firebase)
    if (platform === 'YOUTUBE') {
      if (!isFirebaseConfigured()) {
        setShowFirebaseModal(true);
        return;
      }

      try {
        setConnectingPlatform('YOUTUBE');
        const auth = getFirebaseAuth();
        const result = await signInWithPopup(auth, googleProvider);
        const credential = GoogleAuthProvider.credentialFromResult(result);
        const idToken = await result.user.getIdToken();
        const accessToken = credential?.accessToken;

        if (!accessToken) {
          throw new Error('Google OAuth did not provide an access token.');
        }

        await api.completeOAuth('youtube', {
          idToken,
          accessToken,
        });

        setSuccessBanner('YouTube channel successfully linked! Real channel data and statistics loaded.');
        await loadAccounts();
      } catch (err: unknown) {
        console.error('YouTube connection failed:', err);
        const msg = err instanceof Error ? err.message : 'Google authentication was cancelled or failed.';
        if (!msg.includes('popup-closed-by-user')) {
          setErrorMessage(msg);
        }
      } finally {
        setConnectingPlatform(null);
      }
      return;
    }

    // 2. Instagram Flow (Facebook OAuth via Firebase + IG Business chain)
    if (platform === 'INSTAGRAM') {
      if (!isFirebaseConfigured()) {
        setShowFirebaseModal(true);
        return;
      }

      try {
        setConnectingPlatform('INSTAGRAM');
        const auth = getFirebaseAuth();
        const result = await signInWithPopup(auth, facebookProvider);
        const credential = FacebookAuthProvider.credentialFromResult(result);
        const idToken = await result.user.getIdToken();
        const accessToken = credential?.accessToken;

        if (!accessToken) {
          throw new Error('Meta OAuth did not return an access token.');
        }

        await api.completeOAuth('instagram', {
          idToken,
          accessToken,
        });

        setSuccessBanner('Instagram Professional account linked successfully! Follower count and media details synced.');
        await loadAccounts();
      } catch (err: unknown) {
        console.error('Instagram connection failed:', err);
        const msg = err instanceof Error ? err.message : 'Instagram authentication was cancelled or failed.';
        if (!msg.includes('popup-closed-by-user')) {
          setErrorMessage(msg);
        }
      } finally {
        setConnectingPlatform(null);
      }
      return;
    }

    // 3. TikTok Flow (Custom Server-Side PKCE)
    if (platform === 'TIKTOK') {
      try {
        setConnectingPlatform('TIKTOK');
        const res = await api.getTikTokAuthUrl();
        if (res.redirectUrl) {
          window.location.href = res.redirectUrl;
        } else {
          throw new Error('No authorization URL returned from server.');
        }
      } catch (err: unknown) {
        console.error('TikTok auth redirect failed:', err);
        const msg = err instanceof Error ? err.message : 'Failed to initialize TikTok OAuth PKCE handshake.';
        setErrorMessage(msg);
        setConnectingPlatform(null);
      }
      return;
    }

    // 4. Other Platforms
    setErrorMessage(`${platform} official OAuth integration is queued in the next pipeline phase.`);
  };

  const handleDisconnect = async (accountId: string, platformName: string) => {
    if (!confirm(`Are you sure you want to disconnect ${platformName}? All pending publishing jobs to this channel will be paused.`)) {
      return;
    }
    try {
      await api.disconnectAccount(accountId);
      setSuccessBanner(`${platformName} disconnected.`);
      await loadAccounts();
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : 'Failed to disconnect account';
      setErrorMessage(msg);
    }
  };

  const copyEnvSnippet = () => {
    const snippet = `NEXT_PUBLIC_FIREBASE_API_KEY=your_api_key_here
NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN=your_project.firebaseapp.com
NEXT_PUBLIC_FIREBASE_PROJECT_ID=your_project_id
NEXT_PUBLIC_FIREBASE_STORAGE_BUCKET=your_project.appspot.com
NEXT_PUBLIC_FIREBASE_MESSAGING_SENDER_ID=1234567890
NEXT_PUBLIC_FIREBASE_APP_ID=1:1234567890:web:abcdef123456`;
    navigator.clipboard.writeText(snippet);
    setCopiedEnv(true);
    setTimeout(() => setCopiedEnv(false), 2500);
  };

  return (
    <AppShell pageTitle="Connections">
      <div className="space-y-6 max-w-7xl mx-auto pb-12">
        {/* Header matching SaaS Specification */}
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center border-b border-slate-200/80 pb-5">
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-bold tracking-tight text-slate-900">Platform Connections</h1>
              <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-semibold text-blue-700 border border-blue-200">
                <Shield className="h-3 w-3" />
                OAuth 2.0 PKCE Vault
              </span>
            </div>
            <p className="text-sm text-slate-500 mt-1">
              Connect real social profiles to publish videos, track live metrics, and manage permissions.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={loadAccounts}
              disabled={loading}
              className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 active:scale-95 transition-all disabled:opacity-60"
            >
              <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
              <span>Refresh</span>
            </button>
          </div>
        </div>

        {/* Success Alert Banner */}
        {successBanner && (
          <div className="rounded-xl border border-emerald-200 bg-emerald-50/90 p-4 shadow-sm flex items-start justify-between">
            <div className="flex items-center gap-3">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-600 text-white font-bold">
                <Check className="h-4 w-4" />
              </div>
              <div>
                <p className="text-xs font-bold text-emerald-900">Success</p>
                <p className="text-xs text-emerald-800">{successBanner}</p>
              </div>
            </div>
            <button
              onClick={() => setSuccessBanner(null)}
              className="text-xs font-semibold text-emerald-700 hover:text-emerald-900 px-2 py-1"
            >
              Dismiss
            </button>
          </div>
        )}

        {/* Error Alert Banner */}
        {errorMessage && (
          <div className="rounded-xl border border-rose-200 bg-rose-50/90 p-4 shadow-sm flex items-start justify-between">
            <div className="flex items-center gap-3">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-600 text-white font-bold">
                <AlertTriangle className="h-4 w-4" />
              </div>
              <div>
                <p className="text-xs font-bold text-rose-900">Connection Notice</p>
                <p className="text-xs text-rose-800">{errorMessage}</p>
              </div>
            </div>
            <button
              onClick={() => setErrorMessage(null)}
              className="text-xs font-semibold text-rose-700 hover:text-rose-900 px-2 py-1"
            >
              Dismiss
            </button>
          </div>
        )}

        {/* Platform Cards Grid */}
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {PLATFORMS.map((platform) => {
            const account = accounts.find((a) => a.platform === platform.key && a.status !== 'REVOKED');
            const isConnected = !!account;
            const isExpired = account?.status === 'EXPIRED';
            const isActionRequired = account?.status === 'ACTION_REQUIRED';
            const isConnecting = connectingPlatform === platform.key;

            // Extract real metadata
            const metadata = (account?.metadata || {}) as Record<string, unknown>;
            const followers = metadata.subscriberCount ?? metadata.followersCount;
            const videos = metadata.videoCount ?? metadata.mediaCount;
            const views = metadata.viewCount;

            return (
              <div
                key={platform.key}
                className={`saas-card rounded-2xl p-5 flex flex-col justify-between transition-all duration-200 border ${
                  isConnected
                    ? 'border-slate-200/90 bg-white shadow-sm hover:shadow-md'
                    : 'border-slate-200/60 bg-slate-50/50 hover:bg-white hover:border-slate-300 hover:shadow-sm'
                }`}
              >
                <div className="space-y-4">
                  {/* Top Row: Icon + Platform + Status Badge */}
                  <div className="flex items-start justify-between">
                    <div className="flex items-center gap-3">
                      {isConnected && account?.avatar_url ? (
                        <div className="relative">
                          <img
                            src={account.avatar_url}
                            alt={account.account_name}
                            className="h-11 w-11 rounded-xl object-cover ring-2 ring-blue-500/20 shadow-sm"
                          />
                          <div
                            className={`absolute -bottom-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full text-white text-[9px] font-bold shadow ${platform.iconBg}`}
                          >
                            {platform.iconText}
                          </div>
                        </div>
                      ) : (
                        <div
                          className={`flex h-11 w-11 items-center justify-center rounded-xl text-white font-bold text-sm shadow-sm ${platform.iconBg}`}
                        >
                          {platform.iconText}
                        </div>
                      )}

                      <div>
                        <h3 className="text-sm font-bold text-slate-900 flex items-center gap-1.5">
                          {isConnected ? account.account_name : platform.name}
                        </h3>
                        <p className="text-[11px] font-medium text-slate-500">
                          {isConnected ? account.account_handle || `@${platform.name.toLowerCase()}` : platform.category}
                        </p>
                      </div>
                    </div>

                    {/* Status Badge */}
                    {isConnected ? (
                      isExpired ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-0.5 text-[10px] font-bold text-amber-700 border border-amber-200">
                          <AlertTriangle className="h-3 w-3" />
                          Expired
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[10px] font-bold text-emerald-700 border border-emerald-200">
                          <CheckCircle2 className="h-3 w-3" />
                          Live Connected
                        </span>
                      )
                    ) : (
                      <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-medium text-slate-500 border border-slate-200/60">
                        Not Connected
                      </span>
                    )}
                  </div>

                  {/* Real Live Metrics (Shown when connected) */}
                  {isConnected ? (
                    <div className="rounded-xl bg-slate-50 border border-slate-100 p-3 space-y-2">
                      <div className="grid grid-cols-3 gap-2 text-center divide-x divide-slate-200">
                        <div>
                          <p className="text-[10px] uppercase font-semibold tracking-wider text-slate-400">
                            {platform.key === 'YOUTUBE' ? 'Subscribers' : 'Followers'}
                          </p>
                          <p className="text-xs font-bold text-slate-900 mt-0.5">
                            {formatMetric(followers ?? 0)}
                          </p>
                        </div>
                        <div>
                          <p className="text-[10px] uppercase font-semibold tracking-wider text-slate-400">
                            {platform.key === 'YOUTUBE' ? 'Videos' : 'Posts'}
                          </p>
                          <p className="text-xs font-bold text-slate-900 mt-0.5">
                            {formatMetric(videos ?? 0)}
                          </p>
                        </div>
                        <div>
                          <p className="text-[10px] uppercase font-semibold tracking-wider text-slate-400">
                            {views !== undefined ? 'Views' : 'Status'}
                          </p>
                          <p className="text-xs font-bold text-slate-900 mt-0.5">
                            {views !== undefined ? formatMetric(views) : 'Ready'}
                          </p>
                        </div>
                      </div>

                      <div className="pt-2 border-t border-slate-200/60 flex items-center justify-between text-[10px] text-slate-500">
                        <span className="flex items-center gap-1 font-medium text-emerald-700">
                          <Lock className="h-3 w-3" />
                          AES-256 Encrypted
                        </span>
                        <span>{formatRelativeDays(account.token_expires_at)}</span>
                      </div>
                    </div>
                  ) : (
                    /* Description & Scope Highlights */
                    <div className="space-y-2">
                      <p className="text-xs text-slate-600 line-clamp-2 leading-relaxed">
                        {platform.description}
                      </p>
                      <div className="flex flex-wrap gap-1 pt-1">
                        {platform.scopes.slice(0, 2).map((sc) => (
                          <span
                            key={sc}
                            className="inline-flex items-center rounded-md bg-white border border-slate-200 px-2 py-0.5 text-[10px] font-mono text-slate-600"
                          >
                            {sc}
                          </span>
                        ))}
                      </div>
                    </div>
                  )}
                </div>

                {/* Bottom Action Footer */}
                <div className="pt-4 mt-4 border-t border-slate-100 flex items-center justify-between">
                  {isConnected ? (
                    <>
                      <button
                        onClick={() => handleDisconnect(account.id, platform.name)}
                        className="text-xs font-medium text-slate-400 hover:text-red-600 flex items-center gap-1 transition-colors"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                        <span>Disconnect</span>
                      </button>

                      <button
                        onClick={() => handleConnect(platform.key)}
                        disabled={isConnecting}
                        className={`rounded-lg px-3.5 py-1.5 text-xs font-semibold shadow-sm transition-all flex items-center gap-1.5 ${
                          isExpired
                            ? 'bg-amber-600 hover:bg-amber-700 text-white'
                            : 'border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 active:scale-95'
                        }`}
                      >
                        {isConnecting ? (
                          <>
                            <RefreshCw className="h-3 w-3 animate-spin" />
                            <span>Authenticating...</span>
                          </>
                        ) : (
                          <>
                            <span>{isExpired ? 'Reauthorize' : 'Sync Profile'}</span>
                          </>
                        )}
                      </button>
                    </>
                  ) : (
                    <button
                      onClick={() => handleConnect(platform.key)}
                      disabled={isConnecting}
                      className="w-full flex items-center justify-center gap-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white py-2.5 text-xs font-semibold shadow-sm hover:shadow transition-all active:scale-[0.98] disabled:opacity-60"
                    >
                      {isConnecting ? (
                        <>
                          <RefreshCw className="h-3.5 w-3.5 animate-spin" />
                          <span>Connecting OAuth...</span>
                        </>
                      ) : (
                        <>
                          <Plus className="h-3.5 w-3.5" />
                          <span>Connect {platform.name}</span>
                        </>
                      )}
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>

        {/* Security & Token Architecture Banner */}
        <div className="rounded-2xl border border-slate-200 bg-gradient-to-r from-blue-50/60 via-slate-50 to-indigo-50/60 p-6 space-y-3 shadow-sm">
          <div className="flex items-center gap-2 text-slate-900">
            <Shield className="h-5 w-5 text-blue-600" />
            <h3 className="text-sm font-bold">Enterprise Security & Token Architecture</h3>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs text-slate-600">
            <div className="p-3 bg-white rounded-xl border border-slate-200/80 shadow-xs space-y-1">
              <p className="font-bold text-slate-800 flex items-center gap-1">
                <Lock className="h-3.5 w-3.5 text-blue-600" />
                Zero-Password Storage
              </p>
              <p className="text-[11px] text-slate-500">
                OmniPublish uses official OAuth 2.0 PKCE grants. User passwords are never requested or stored.
              </p>
            </div>
            <div className="p-3 bg-white rounded-xl border border-slate-200/80 shadow-xs space-y-1">
              <p className="font-bold text-slate-800 flex items-center gap-1">
                <Shield className="h-3.5 w-3.5 text-emerald-600" />
                AES-256 Vault Encryption
              </p>
              <p className="text-[11px] text-slate-500">
                All platform access tokens and refresh tokens are encrypted at rest with AES-256-CBC cipher envelopes.
              </p>
            </div>
            <div className="p-3 bg-white rounded-xl border border-slate-200/80 shadow-xs space-y-1">
              <p className="font-bold text-slate-800 flex items-center gap-1">
                <RefreshCw className="h-3.5 w-3.5 text-indigo-600" />
                Auto-Refresh Daemon
              </p>
              <p className="text-[11px] text-slate-500">
                Tokens approaching expiration are automatically renewed by the TokenRefreshService before publishing runs.
              </p>
            </div>
          </div>
        </div>

        {/* Firebase Config Modal (Shown when clicking connect if env variables are empty) */}
        {showFirebaseModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
            <div className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl space-y-5 animate-in fade-in zoom-in-95 duration-200">
              <div className="flex items-start justify-between pb-3 border-b border-slate-100">
                <div>
                  <h3 className="text-base font-bold text-slate-900 flex items-center gap-2">
                    <Sparkles className="h-4 w-4 text-amber-500" />
                    Firebase Credentials Setup
                  </h3>
                  <p className="text-xs text-slate-500 mt-0.5">
                    Connect real YouTube and Meta accounts using your Firebase project.
                  </p>
                </div>
                <button
                  onClick={() => setShowFirebaseModal(false)}
                  className="text-slate-400 hover:text-slate-600 p-1"
                >
                  ✕
                </button>
              </div>

              <div className="rounded-xl bg-amber-50 border border-amber-200/80 p-3.5 text-xs text-amber-900 space-y-1.5">
                <p className="font-bold flex items-center gap-1.5 text-amber-800">
                  <Info className="h-4 w-4" />
                  Firebase project configuration required
                </p>
                <p className="text-[11px] text-amber-700 leading-relaxed">
                  To authenticate with Google and Facebook consent dialogs, add your Firebase Web App credentials to{' '}
                  <code className="font-mono font-bold bg-amber-100 px-1 py-0.5 rounded">frontend/.env.local</code>.
                </p>
              </div>

              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <span className="text-xs font-semibold text-slate-700">Add to frontend/.env.local:</span>
                  <button
                    onClick={copyEnvSnippet}
                    className="flex items-center gap-1 text-[11px] font-semibold text-blue-600 hover:text-blue-700"
                  >
                    {copiedEnv ? (
                      <>
                        <Check className="h-3 w-3 text-emerald-600" />
                        <span className="text-emerald-600">Copied!</span>
                      </>
                    ) : (
                      <>
                        <Copy className="h-3 w-3" />
                        <span>Copy Template</span>
                      </>
                    )}
                  </button>
                </div>
                <pre className="p-3 bg-slate-900 text-slate-100 rounded-xl text-[11px] font-mono overflow-x-auto leading-relaxed border border-slate-800">
{`NEXT_PUBLIC_FIREBASE_API_KEY=AIzaSy...
NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN=omnipublish-studio.firebaseapp.com
NEXT_PUBLIC_FIREBASE_PROJECT_ID=omnipublish-studio
NEXT_PUBLIC_FIREBASE_STORAGE_BUCKET=omnipublish-studio.appspot.com
NEXT_PUBLIC_FIREBASE_MESSAGING_SENDER_ID=1234567890
NEXT_PUBLIC_FIREBASE_APP_ID=1:1234567890:web:abcdef123456`}
                </pre>
              </div>

              <div className="rounded-lg bg-slate-50 border border-slate-200 p-3 text-xs text-slate-600 space-y-1">
                <p className="font-semibold text-slate-800">Where to get these keys:</p>
                <p className="text-[11px] text-slate-500">
                  1. Visit <a href="https://console.firebase.google.com" target="_blank" rel="noreferrer" className="text-blue-600 underline font-semibold">Firebase Console</a> and create or select a project.
                </p>
                <p className="text-[11px] text-slate-500">
                  2. Enable Authentication → Sign-in method → Enable <strong>Google</strong> & <strong>Facebook</strong>.
                </p>
                <p className="text-[11px] text-slate-500">
                  3. Project Settings → General → Web App → Copy the <code>firebaseConfig</code> object.
                </p>
              </div>

              <div className="flex justify-end gap-2 pt-2">
                <button
                  onClick={() => setShowFirebaseModal(false)}
                  className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
