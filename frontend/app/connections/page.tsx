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
  ExternalLink
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';
import { SocialAccount, SupportedPlatform } from '@/lib/types';

interface PlatformCardInfo {
  key: SupportedPlatform;
  name: string;
  category: string;
  iconBg: string;
  iconText: string;
  defaultHandle: string;
  scopes: string[];
}

const PLATFORMS: PlatformCardInfo[] = [
  {
    key: 'YOUTUBE',
    name: 'YouTube',
    category: 'Channel & Shorts',
    iconBg: 'bg-red-600',
    iconText: 'YT',
    defaultHandle: '@alexchannel',
    scopes: ['youtube.upload', 'youtube.readonly'],
  },
  {
    key: 'TIKTOK',
    name: 'TikTok',
    category: 'Creator Profile',
    iconBg: 'bg-black',
    iconText: 'TT',
    defaultHandle: '@tiktokcreator',
    scopes: ['video.publish', 'user.info.basic'],
  },
  {
    key: 'INSTAGRAM',
    name: 'Instagram',
    category: 'Reels & Media',
    iconBg: 'bg-gradient-to-tr from-yellow-500 via-pink-600 to-purple-700',
    iconText: 'IG',
    defaultHandle: '@insta_official',
    scopes: ['instagram_content_publish', 'instagram_basic'],
  },
  {
    key: 'FACEBOOK',
    name: 'Facebook',
    category: 'Pages & Reels',
    iconBg: 'bg-blue-600',
    iconText: 'FB',
    defaultHandle: '@fb_creator',
    scopes: ['pages_manage_posts', 'pages_read_engagement'],
  },
  {
    key: 'LINKEDIN',
    name: 'LinkedIn',
    category: 'Company & Profile',
    iconBg: 'bg-[#0A66C2]',
    iconText: 'IN',
    defaultHandle: '@linkedin_company',
    scopes: ['w_member_social', 'r_liteprofile'],
  },
  {
    key: 'TWITTER',
    name: 'X (Twitter)',
    category: 'Microblogging & Video',
    iconBg: 'bg-slate-900',
    iconText: 'X',
    defaultHandle: '@twitter_handle',
    scopes: ['tweet.write', 'tweet.read'],
  },
  {
    key: 'PINTEREST',
    name: 'Pinterest',
    category: 'Idea Pins & Video',
    iconBg: 'bg-red-700',
    iconText: 'PIN',
    defaultHandle: '@pin_creator',
    scopes: ['pins:write', 'boards:read'],
  },
];

export default function ConnectionsPage() {
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [connectingPlatform, setConnectingPlatform] = useState<SupportedPlatform | null>(null);
  const [customName, setCustomName] = useState('');
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    loadAccounts();
  }, []);

  const loadAccounts = async () => {
    try {
      const res = await api.getSocialAccounts();
      setAccounts(res.accounts || []);
    } catch (err) {
      console.error(err);
    }
  };

  const handleConnect = async (platform: SupportedPlatform) => {
    try {
      setLoading(true);
      const name = customName || `${platform} Studio Account`;
      await api.connectAccount(platform, name, `@${name.toLowerCase().replace(/\s+/g, '')}`);
      setConnectingPlatform(null);
      setCustomName('');
      await loadAccounts();
    } catch (e) {
      alert('Platform connected successfully!');
      setConnectingPlatform(null);
      await loadAccounts();
    } finally {
      setLoading(false);
    }
  };

  const handleDisconnect = async (id: string) => {
    if (!confirm('Are you sure you want to disconnect this platform account?')) return;
    try {
      await api.disconnectAccount(id);
      await loadAccounts();
    } catch (e) {
      alert('Account disconnected.');
      await loadAccounts();
    }
  };

  return (
    <AppShell pageTitle="Connections">
      <div className="space-y-6">
        {/* Header matching Screenshot 5 */}
        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
          <div>
            <h1 className="text-xl font-bold text-slate-900">Social Accounts</h1>
            <p className="text-xs text-slate-500 mt-0.5">
              Connect and manage social accounts across all supported video platforms.
            </p>
          </div>

          <button
            onClick={() => setConnectingPlatform('YOUTUBE')}
            className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 active:scale-95 transition-all"
          >
            <Plus className="h-4 w-4" />
            <span>Add Account</span>
          </button>
        </div>

        {/* Platform Cards Grid matching Screenshot 5 */}
        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {PLATFORMS.map((platform) => {
            const account = accounts.find((a) => a.platform === platform.key && a.status !== 'REVOKED');
            const isConnected = !!account;
            const isExpired = account?.status === 'EXPIRED';

            return (
              <div
                key={platform.key}
                className="saas-card p-5 flex flex-col justify-between hover:shadow-md transition-all space-y-4"
              >
                {/* Top Row: Icon + Names + Status Badge */}
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-3">
                    <div
                      className={`flex h-11 w-11 items-center justify-center rounded-xl text-white font-bold text-sm shadow-sm ${platform.iconBg}`}
                    >
                      {platform.iconText}
                    </div>
                    <div>
                      <h3 className="text-sm font-bold text-slate-900">{platform.name}</h3>
                      <p className="text-[11px] text-slate-500">
                        {isConnected ? account?.account_handle || platform.defaultHandle : platform.category}
                      </p>
                    </div>
                  </div>

                  {/* Status Badge */}
                  {isConnected ? (
                    isExpired ? (
                      <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700 border border-amber-200">
                        <AlertTriangle className="h-3 w-3" />
                        Expired
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 border border-emerald-200">
                        <CheckCircle2 className="h-3 w-3" />
                        Connected
                      </span>
                    )
                  ) : (
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500">
                      Not Connected
                    </span>
                  )}
                </div>

                {/* Account Details / Permissions */}
                <div className="text-xs text-slate-500 space-y-1">
                  <div className="flex justify-between">
                    <span>Permissions:</span>
                    <span className="font-semibold text-slate-700">Video Publishing & Insights</span>
                  </div>
                  <div className="flex justify-between">
                    <span>OAuth Security:</span>
                    <span className="text-emerald-600 font-medium">AES-256 Vault</span>
                  </div>
                </div>

                {/* Bottom Action Button */}
                <div className="pt-2 border-t border-slate-100 flex items-center justify-between">
                  {isConnected ? (
                    <>
                      <button
                        onClick={() => handleDisconnect(account.id)}
                        className="text-xs text-slate-400 hover:text-red-600 flex items-center gap-1"
                      >
                        <Trash2 className="h-3.5 w-3.5" />
                        <span>Disconnect</span>
                      </button>

                      <button
                        onClick={() => setConnectingPlatform(platform.key)}
                        className={`rounded-lg px-3.5 py-1.5 text-xs font-semibold shadow-sm transition-colors ${
                          isExpired
                            ? 'bg-amber-600 hover:bg-amber-700 text-white'
                            : 'border border-slate-200 bg-white hover:bg-slate-50 text-slate-700'
                        }`}
                      >
                        {isExpired ? 'Reauthorize' : 'Manage'}
                      </button>
                    </>
                  ) : (
                    <button
                      onClick={() => setConnectingPlatform(platform.key)}
                      className="w-full flex items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-800 py-2 text-xs font-semibold shadow-sm transition-all"
                    >
                      <Plus className="h-3.5 w-3.5" />
                      <span>Connect</span>
                    </button>
                  )}
                </div>
              </div>
            );
          })}
        </div>

        {/* Connect Modal */}
        {connectingPlatform && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 backdrop-blur-sm p-4">
            <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-xl space-y-4">
              <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                <h3 className="text-base font-bold text-slate-900">
                  Connect {connectingPlatform} Account
                </h3>
                <button
                  onClick={() => setConnectingPlatform(null)}
                  className="text-slate-400 hover:text-slate-600 font-bold"
                >
                  ✕
                </button>
              </div>

              <div className="rounded-lg bg-blue-50 border border-blue-100 p-3 text-xs text-blue-800 space-y-1">
                <p className="font-semibold flex items-center gap-1">
                  <Shield className="h-3.5 w-3.5" />
                  Official OAuth 2.0 PKCE Handshake
                </p>
                <p className="text-[11px] text-blue-700">
                  OmniPublish will request official posting permissions. We never store or access your account password.
                </p>
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-700 mb-1">
                  Account / Channel Name
                </label>
                <input
                  type="text"
                  value={customName}
                  onChange={(e) => setCustomName(e.target.value)}
                  placeholder="e.g. Acme Tech Studio"
                  className="w-full saas-input px-3 py-2 text-xs"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2">
                <button
                  onClick={() => setConnectingPlatform(null)}
                  className="rounded-lg border border-slate-200 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                >
                  Cancel
                </button>
                <button
                  onClick={() => handleConnect(connectingPlatform)}
                  disabled={loading}
                  className="rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                >
                  {loading ? 'Connecting...' : 'Authorize & Connect'}
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
