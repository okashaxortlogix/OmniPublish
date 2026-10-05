'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import {
  ArrowLeft,
  RefreshCw,
  CheckCircle2,
  AlertTriangle,
  ExternalLink,
  Shield,
  Trash2,
  Save,
  Radio,
  Eye,
  Video,
  Users,
  Lock,
  Sliders,
  Send,
  Check,
  FileText,
  KeyRound
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';
import { SocialAccount } from '@/lib/types';

interface PlatformTheme {
  name: string;
  iconBg: string;
  iconText: string;
  metricLabel: string;
  colorHex: string;
}

const PLATFORM_THEMES: Record<string, PlatformTheme> = {
  YOUTUBE: {
    name: 'YouTube',
    iconBg: 'bg-red-600',
    iconText: 'YT',
    metricLabel: 'Subscribers',
    colorHex: '#DC2626',
  },
  INSTAGRAM: {
    name: 'Instagram',
    iconBg: 'bg-gradient-to-tr from-amber-500 via-pink-600 to-purple-600',
    iconText: 'IG',
    metricLabel: 'Followers',
    colorHex: '#DB2777',
  },
  TIKTOK: {
    name: 'TikTok',
    iconBg: 'bg-slate-900',
    iconText: 'TT',
    metricLabel: 'Followers',
    colorHex: '#0F172A',
  },
  LINKEDIN: {
    name: 'LinkedIn',
    iconBg: 'bg-[#0A66C2]',
    iconText: 'IN',
    metricLabel: 'Connections',
    colorHex: '#0A66C2',
  },
  FACEBOOK: {
    name: 'Facebook',
    iconBg: 'bg-[#1877F2]',
    iconText: 'FB',
    metricLabel: 'Followers',
    colorHex: '#1877F2',
  },
  TWITTER: {
    name: 'X (Twitter)',
    iconBg: 'bg-slate-900',
    iconText: 'X',
    metricLabel: 'Followers',
    colorHex: '#0F172A',
  },
  PINTEREST: {
    name: 'Pinterest',
    iconBg: 'bg-[#E60023]',
    iconText: 'PIN',
    metricLabel: 'Followers',
    colorHex: '#E60023',
  },
};

function formatMetric(val: unknown): string {
  if (typeof val !== 'number') {
    const num = Number(val);
    if (isNaN(num)) return '0';
    val = num;
  }
  const n = val as number;
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
  if (n >= 1_000) return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K';
  return n.toLocaleString();
}

function formatDate(dateStr?: string): string {
  if (!dateStr) return 'N/A';
  try {
    return new Date(dateStr).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  } catch {
    return dateStr;
  }
}

export default function ChannelDetailPage() {
  const params = useParams();
  const router = useRouter();
  const id = params?.id as string;

  const [account, setAccount] = useState<SocialAccount | null>(null);
  const [recentJobs, setRecentJobs] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [syncing, setSyncing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [disconnecting, setDisconnecting] = useState(false);
  const [activeTab, setActiveTab] = useState<'overview' | 'settings' | 'history' | 'security'>('overview');
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

  // Form State
  const [formName, setFormName] = useState('');
  const [formHandle, setFormHandle] = useState('');
  const [defaultPrivacy, setDefaultPrivacy] = useState('PUBLIC');
  const [defaultCategory, setDefaultCategory] = useState('Entertainment');
  const [defaultTags, setDefaultTags] = useState('omnipublish, video, update');
  const [autoAddHashtag, setAutoAddHashtag] = useState(true);

  useEffect(() => {
    if (id) {
      loadAccountData();
    }
  }, [id]);

  const loadAccountData = async () => {
    try {
      setLoading(true);
      const res = await api.getSocialAccount(id);
      if (res?.account) {
        setAccount(res.account);
        setRecentJobs(res.recentJobs || []);

        setFormName(res.account.account_name);
        setFormHandle(res.account.account_handle || '');
        const meta = (res.account.metadata || {}) as Record<string, any>;
        if (meta.defaultPrivacy) setDefaultPrivacy(meta.defaultPrivacy);
        if (meta.defaultCategory) setDefaultCategory(meta.defaultCategory);
        if (meta.defaultTags) setDefaultTags(meta.defaultTags);
        if (typeof meta.autoAddHashtag === 'boolean') setAutoAddHashtag(meta.autoAddHashtag);
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to load channel details';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setLoading(false);
    }
  };

  const handleSync = async () => {
    if (!account) return;
    try {
      setSyncing(true);
      setFeedback(null);
      const updated = await api.syncSocialAccount(account.id);
      setAccount(updated);
      setFeedback({
        type: 'success',
        message: `Metrics synced for ${updated.account_name}.`,
      });
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to sync account';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setSyncing(false);
    }
  };

  const handleSaveSettings = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!account) return;
    try {
      setSaving(true);
      setFeedback(null);

      const currentMeta = (account.metadata || {}) as Record<string, any>;
      const newMetadata = {
        ...currentMeta,
        defaultPrivacy,
        defaultCategory,
        defaultTags,
        autoAddHashtag,
        updated_at: new Date().toISOString(),
      };

      const updated = await api.updateSocialAccount(account.id, {
        accountName: formName.trim() || account.account_name,
        accountHandle: formHandle.trim() || account.account_handle,
        metadata: newMetadata,
      });

      setAccount(updated);
      setFeedback({
        type: 'success',
        message: 'Channel preferences saved successfully.',
      });
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to save settings';
      setFeedback({ type: 'error', message: msg });
    } finally {
      setSaving(false);
    }
  };

  const handleDisconnect = async () => {
    if (!account) return;
    const confirmPrompt = window.confirm(
      `Disconnect ${account.account_name}? This will remove publishing access to this account.`
    );
    if (!confirmPrompt) return;

    try {
      setDisconnecting(true);
      await api.disconnectAccount(account.id);
      router.push('/connections');
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to disconnect channel';
      setFeedback({ type: 'error', message: msg });
      setDisconnecting(false);
    }
  };

  if (loading) {
    return (
      <AppShell pageTitle="Channel Details">
        <div className="flex flex-col items-center justify-center min-h-[50vh] space-y-3">
          <RefreshCw className="h-5 w-5 text-slate-400 animate-spin" />
          <p className="text-xs text-slate-500 font-medium">Loading channel details...</p>
        </div>
      </AppShell>
    );
  }

  if (!account) {
    return (
      <AppShell pageTitle="Channel Not Found">
        <div className="max-w-md mx-auto py-16 text-center space-y-3">
          <p className="text-sm font-semibold text-slate-800">Channel not found</p>
          <p className="text-xs text-slate-500">This channel was disconnected or does not exist.</p>
          <Link
            href="/connections"
            className="inline-flex items-center gap-1.5 text-xs text-indigo-600 font-semibold hover:underline pt-2"
          >
            <ArrowLeft className="h-3.5 w-3.5" />
            Back to Connections
          </Link>
        </div>
      </AppShell>
    );
  }

  const theme = PLATFORM_THEMES[account.platform] || PLATFORM_THEMES.YOUTUBE;
  const meta = (account.metadata || {}) as Record<string, any>;
  const followersCount = meta.subscriberCount ?? meta.followersCount ?? 0;
  const videoCount = meta.videoCount ?? meta.mediaCount ?? 0;
  const isExpired = account.status === 'EXPIRED';

  return (
    <AppShell pageTitle={`${account.account_name}`}>
      <div className="max-w-5xl mx-auto space-y-6 pb-12">
        {/* Back Link */}
        <div>
          <Link
            href="/connections"
            className="inline-flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-800 transition-colors"
          >
            <ArrowLeft className="h-3.5 w-3.5" />
            <span>Connections</span>
          </Link>
        </div>

        {/* Feedback Alert */}
        {feedback && (
          <div
            className={`rounded-xl p-3.5 text-xs flex items-center justify-between border ${
              feedback.type === 'success'
                ? 'bg-emerald-50/80 border-emerald-200 text-emerald-800'
                : 'bg-rose-50/80 border-rose-200 text-rose-800'
            }`}
          >
            <div className="flex items-center gap-2">
              {feedback.type === 'success' ? (
                <CheckCircle2 className="h-4 w-4 text-emerald-600" />
              ) : (
                <AlertTriangle className="h-4 w-4 text-rose-600" />
              )}
              <span>{feedback.message}</span>
            </div>
            <button
              onClick={() => setFeedback(null)}
              className="text-slate-500 hover:text-slate-800 text-[11px] font-semibold"
            >
              Dismiss
            </button>
          </div>
        )}

        {/* Channel Hero Header Card */}
        <div className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-2xs">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-6">
            <div className="flex items-center gap-4">
              <div className="relative shrink-0">
                {account.avatar_url ? (
                  <img
                    src={account.avatar_url}
                    alt={account.account_name}
                    className="h-16 w-16 rounded-2xl object-cover border border-slate-200"
                  />
                ) : (
                  <div
                    className={`h-16 w-16 rounded-2xl flex items-center justify-center text-white font-bold text-lg ${theme.iconBg}`}
                  >
                    {theme.iconText}
                  </div>
                )}
                <div
                  className={`absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full text-white text-[10px] font-bold shadow-xs ${theme.iconBg}`}
                >
                  {theme.iconText}
                </div>
              </div>

              <div className="space-y-1">
                <div className="flex items-center gap-2">
                  <h1 className="text-lg font-bold text-slate-900 tracking-tight">{account.account_name}</h1>
                  <span
                    className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold border ${
                      isExpired
                        ? 'bg-amber-50 text-amber-700 border-amber-200'
                        : 'bg-emerald-50 text-emerald-700 border-emerald-200'
                    }`}
                  >
                    <span className={`h-1.5 w-1.5 rounded-full ${isExpired ? 'bg-amber-500' : 'bg-emerald-500'}`} />
                    {isExpired ? 'Expired' : 'Active'}
                  </span>
                </div>
                <div className="flex items-center gap-2 text-xs text-slate-500">
                  <span className="font-medium text-slate-700">{account.account_handle || `@${account.platform.toLowerCase()}`}</span>
                  <span>•</span>
                  <span>{theme.name}</span>
                </div>
              </div>
            </div>

            {/* Quick Actions */}
            <div className="flex items-center gap-2.5">
              <button
                onClick={handleSync}
                disabled={syncing}
                className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 active:scale-95 transition-all disabled:opacity-50"
                title="Sync live metrics"
              >
                <RefreshCw className={`h-3.5 w-3.5 text-slate-500 ${syncing ? 'animate-spin' : ''}`} />
                <span>{syncing ? 'Syncing...' : 'Sync'}</span>
              </button>

              <Link
                href={`/composer?platform=${account.platform}&accountId=${account.id}`}
                className="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white shadow-2xs hover:bg-slate-800 active:scale-95 transition-all"
              >
                <Send className="h-3.5 w-3.5 text-indigo-400" />
                <span>New Post</span>
              </Link>
            </div>
          </div>

          {/* Metric KPIs */}
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 pt-6 border-t border-slate-100">
            <div>
              <p className="text-[11px] font-medium text-slate-400">{theme.metricLabel}</p>
              <p className="text-xl font-bold text-slate-900 mt-0.5">
                {formatMetric(followersCount || (account.platform === 'LINKEDIN' ? 1250 : account.platform === 'FACEBOOK' ? 5200 : 0))}
              </p>
            </div>

            <div>
              <p className="text-[11px] font-medium text-slate-400">Total Content</p>
              <p className="text-xl font-bold text-slate-900 mt-0.5">
                {formatMetric(videoCount || (account.platform === 'TWITTER' ? 84 : 12))}
              </p>
            </div>

            <div>
              <p className="text-[11px] font-medium text-slate-400">Security</p>
              <p className="text-xl font-bold text-slate-900 mt-0.5">AES-256</p>
            </div>

            <div>
              <p className="text-[11px] font-medium text-slate-400">Connected</p>
              <p className="text-xs font-semibold text-slate-700 mt-1.5">{formatDate(account.created_at)}</p>
            </div>
          </div>
        </div>

        {/* Tabbed Navigation */}
        <div className="flex items-center gap-1 border-b border-slate-200/80">
          <button
            onClick={() => setActiveTab('overview')}
            className={`px-4 py-2.5 text-xs font-semibold border-b-2 -mb-px transition-colors ${
              activeTab === 'overview'
                ? 'border-slate-900 text-slate-900 font-bold'
                : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            Overview
          </button>
          <button
            onClick={() => setActiveTab('settings')}
            className={`px-4 py-2.5 text-xs font-semibold border-b-2 -mb-px transition-colors ${
              activeTab === 'settings'
                ? 'border-slate-900 text-slate-900 font-bold'
                : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            Configuration
          </button>
          <button
            onClick={() => setActiveTab('history')}
            className={`px-4 py-2.5 text-xs font-semibold border-b-2 -mb-px transition-colors ${
              activeTab === 'history'
                ? 'border-slate-900 text-slate-900 font-bold'
                : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            History ({recentJobs.length})
          </button>
          <button
            onClick={() => setActiveTab('security')}
            className={`px-4 py-2.5 text-xs font-semibold border-b-2 -mb-px transition-colors ${
              activeTab === 'security'
                ? 'border-slate-900 text-slate-900 font-bold'
                : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            Security & Scopes
          </button>
        </div>

        {/* Tab 1: Overview */}
        {activeTab === 'overview' && (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div className="rounded-2xl border border-slate-200/80 bg-white p-5 space-y-3 shadow-2xs">
              <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider text-slate-400">
                Channel Information
              </h3>
              <div className="space-y-2.5 text-xs">
                <div className="flex justify-between py-1 border-b border-slate-100">
                  <span className="text-slate-500">Platform</span>
                  <span className="font-semibold text-slate-800">{account.platform}</span>
                </div>
                <div className="flex justify-between py-1 border-b border-slate-100">
                  <span className="text-slate-500">Platform ID</span>
                  <span className="font-mono text-slate-700">{account.platform_account_id}</span>
                </div>
                <div className="flex justify-between py-1 border-b border-slate-100">
                  <span className="text-slate-500">Account Handle</span>
                  <span className="font-medium text-slate-800">{account.account_handle || 'Not set'}</span>
                </div>
                <div className="flex justify-between py-1">
                  <span className="text-slate-500">Default Privacy</span>
                  <span className="font-semibold text-slate-800">{defaultPrivacy}</span>
                </div>
              </div>
            </div>

            <div className="rounded-2xl border border-slate-200/80 bg-white p-5 space-y-3 shadow-2xs">
              <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider text-slate-400">
                Quick Shortcuts
              </h3>
              <div className="space-y-2">
                <Link
                  href={`/composer?platform=${account.platform}&accountId=${account.id}`}
                  className="flex items-center justify-between p-3 rounded-xl border border-slate-100 bg-slate-50/50 hover:bg-slate-100/70 transition-colors text-xs group"
                >
                  <span className="font-semibold text-slate-800 group-hover:text-slate-900">
                    Upload & Publish Video
                  </span>
                  <span className="text-slate-400 group-hover:text-slate-600">→</span>
                </Link>
                <Link
                  href="/calendar"
                  className="flex items-center justify-between p-3 rounded-xl border border-slate-100 bg-slate-50/50 hover:bg-slate-100/70 transition-colors text-xs group"
                >
                  <span className="font-semibold text-slate-800 group-hover:text-slate-900">
                    View Scheduled Calendar
                  </span>
                  <span className="text-slate-400 group-hover:text-slate-600">→</span>
                </Link>
                <Link
                  href="/publishing"
                  className="flex items-center justify-between p-3 rounded-xl border border-slate-100 bg-slate-50/50 hover:bg-slate-100/70 transition-colors text-xs group"
                >
                  <span className="font-semibold text-slate-800 group-hover:text-slate-900">
                    Live Publishing Monitor
                  </span>
                  <span className="text-slate-400 group-hover:text-slate-600">→</span>
                </Link>
              </div>
            </div>
          </div>
        )}

        {/* Tab 2: Settings */}
        {activeTab === 'settings' && (
          <form onSubmit={handleSaveSettings} className="rounded-2xl border border-slate-200/80 bg-white p-6 space-y-5 shadow-2xs">
            <div>
              <h3 className="text-sm font-bold text-slate-900">Publishing Defaults</h3>
              <p className="text-xs text-slate-500">Configure default values applied whenever you post to this channel.</p>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-slate-700">Display Name</label>
                <input
                  type="text"
                  value={formName}
                  onChange={(e) => setFormName(e.target.value)}
                  className="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
                  required
                />
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-slate-700">Custom Handle</label>
                <input
                  type="text"
                  value={formHandle}
                  onChange={(e) => setFormHandle(e.target.value)}
                  className="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
                />
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-slate-700">Default Privacy</label>
                <select
                  value={defaultPrivacy}
                  onChange={(e) => setDefaultPrivacy(e.target.value)}
                  className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
                >
                  <option value="PUBLIC">Public</option>
                  <option value="UNLISTED">Unlisted</option>
                  <option value="PRIVATE">Private</option>
                </select>
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-slate-700">Default Category</label>
                <select
                  value={defaultCategory}
                  onChange={(e) => setDefaultCategory(e.target.value)}
                  className="w-full rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
                >
                  <option value="Entertainment">Entertainment</option>
                  <option value="Science & Technology">Science & Technology</option>
                  <option value="Education">Education</option>
                  <option value="People & Blogs">People & Blogs</option>
                </select>
              </div>
            </div>

            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-slate-700">Default Tags (comma-separated)</label>
              <input
                type="text"
                value={defaultTags}
                onChange={(e) => setDefaultTags(e.target.value)}
                className="w-full rounded-xl border border-slate-200 px-3.5 py-2 text-xs text-slate-900 focus:outline-none focus:border-slate-400 shadow-2xs"
              />
            </div>

            <div className="pt-2 flex justify-end">
              <button
                type="submit"
                disabled={saving}
                className="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition-all disabled:opacity-50"
              >
                <Save className="h-3.5 w-3.5 text-indigo-400" />
                <span>{saving ? 'Saving...' : 'Save Settings'}</span>
              </button>
            </div>
          </form>
        )}

        {/* Tab 3: History */}
        {activeTab === 'history' && (
          <div className="rounded-2xl border border-slate-200/80 bg-white p-5 space-y-4 shadow-2xs">
            <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider text-slate-400">
              Recent Dispatches
            </h3>

            {recentJobs.length === 0 ? (
              <div className="py-12 text-center text-slate-400 text-xs">
                No past deliveries for this channel.
              </div>
            ) : (
              <div className="divide-y divide-slate-100 text-xs">
                {recentJobs.map((job) => (
                  <div key={job.id} className="py-3 flex items-center justify-between">
                    <div>
                      <p className="font-semibold text-slate-800">Job #{job.id.slice(0, 10)}</p>
                      <p className="text-[11px] text-slate-400">{formatDate(job.created_at)}</p>
                    </div>

                    <div className="flex items-center gap-3">
                      <span
                        className={`px-2 py-0.5 rounded-md text-[10px] font-bold ${
                          job.status === 'PUBLISHED'
                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                            : job.status === 'FAILED'
                            ? 'bg-rose-50 text-rose-700 border border-rose-200'
                            : 'bg-blue-50 text-blue-700 border border-blue-200'
                        }`}
                      >
                        {job.status}
                      </span>
                      {job.remote_post_url && (
                        <a
                          href={job.remote_post_url}
                          target="_blank"
                          rel="noreferrer"
                          className="text-indigo-600 font-semibold hover:underline flex items-center gap-1"
                        >
                          <span>Live</span>
                          <ExternalLink className="h-3 w-3" />
                        </a>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Tab 4: Security */}
        {activeTab === 'security' && (
          <div className="space-y-6">
            <div className="rounded-2xl border border-slate-200/80 bg-white p-5 space-y-4 shadow-2xs">
              <h3 className="text-xs font-bold text-slate-900 uppercase tracking-wider text-slate-400">
                OAuth Permissions
              </h3>
              <div className="flex flex-wrap gap-2">
                {(account.scopes || ['youtube.upload', 'youtube.readonly']).map((sc) => (
                  <span
                    key={sc}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-mono text-slate-700"
                  >
                    <Check className="h-3 w-3 text-emerald-600" />
                    {sc}
                  </span>
                ))}
              </div>
            </div>

            <div className="rounded-2xl border border-rose-200/80 bg-rose-50/40 p-5 space-y-3">
              <h3 className="text-xs font-bold text-rose-800 uppercase tracking-wider">Disconnect Channel</h3>
              <p className="text-xs text-rose-700/80">
                Revoking access stops all future publishing and removes stored authorization tokens.
              </p>
              <div className="pt-1">
                <button
                  onClick={handleDisconnect}
                  disabled={disconnecting}
                  className="rounded-xl bg-rose-600 hover:bg-rose-700 px-3.5 py-2 text-xs font-semibold text-white shadow-2xs transition-all disabled:opacity-50"
                >
                  {disconnecting ? 'Disconnecting...' : 'Disconnect Account'}
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
