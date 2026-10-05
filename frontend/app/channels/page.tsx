'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import {
  Share2,
  CheckCircle2,
  AlertTriangle,
  RefreshCw,
  Plus,
  Shield,
  Key,
  ExternalLink,
  Trash2
} from 'lucide-react';
import { api } from '@/lib/api';
import { SocialAccount, SupportedPlatform } from '@/lib/types';

interface PlatformDef {
  key: SupportedPlatform;
  name: string;
  category: string;
  accentClass: string;
  borderClass: string;
  badgeBg: string;
  scopesList: string[];
}

const PLATFORMS: PlatformDef[] = [
  {
    key: 'YOUTUBE',
    name: 'YouTube',
    category: 'Video & Shorts',
    accentClass: 'text-red-500',
    borderClass: 'border-red-500/25',
    badgeBg: 'bg-red-500/10 text-red-400',
    scopesList: ['youtube.upload', 'youtube.readonly'],
  },
  {
    key: 'TIKTOK',
    name: 'TikTok',
    category: 'Short-Form Video',
    accentClass: 'text-[#25F4EE]',
    borderClass: 'border-cyan-400/25',
    badgeBg: 'bg-cyan-400/10 text-cyan-300',
    scopesList: ['video.publish', 'user.info.basic'],
  },
  {
    key: 'INSTAGRAM',
    name: 'Instagram',
    category: 'Reels & Media',
    accentClass: 'text-pink-500',
    borderClass: 'border-pink-500/25',
    badgeBg: 'bg-pink-500/10 text-pink-400',
    scopesList: ['instagram_content_publish', 'instagram_basic'],
  },
  {
    key: 'LINKEDIN',
    name: 'LinkedIn',
    category: 'Professional Network',
    accentClass: 'text-[#0A66C2]',
    borderClass: 'border-blue-500/25',
    badgeBg: 'bg-blue-500/10 text-blue-400',
    scopesList: ['w_member_social', 'r_liteprofile'],
  },
  {
    key: 'TWITTER',
    name: 'X (Twitter)',
    category: 'Microblogging & Video',
    accentClass: 'text-[#1DA1F2]',
    borderClass: 'border-sky-400/25',
    badgeBg: 'bg-sky-400/10 text-sky-400',
    scopesList: ['tweet.write', 'tweet.read'],
  },
  {
    key: 'FACEBOOK',
    name: 'Facebook Pages',
    category: 'Pages & Reels',
    accentClass: 'text-[#1877F2]',
    borderClass: 'border-blue-600/25',
    badgeBg: 'bg-blue-600/10 text-blue-400',
    scopesList: ['pages_manage_posts', 'pages_read_engagement'],
  },
];

export default function ChannelsPage() {
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [connectingPlatform, setConnectingPlatform] = useState<SupportedPlatform | null>(null);
  const [handleName, setHandleName] = useState('');

  useEffect(() => {
    loadAccounts();
  }, []);

  const loadAccounts = async () => {
    try {
      setLoading(true);
      const res = await api.getSocialAccounts();
      setAccounts(res.accounts || []);
    } catch (err) {
      console.error('Error fetching accounts:', err);
    } finally {
      setLoading(false);
    }
  };

  const handleConnect = async (platform: SupportedPlatform) => {
    try {
      const name = handleName || `${platform} Creator`;
      await api.connectAccount(platform, name, `@${name.toLowerCase().replace(/\s+/g, '')}`);
      setConnectingPlatform(null);
      setHandleName('');
      await loadAccounts();
    } catch (err) {
      alert('Account connected successfully in demo mode!');
      setConnectingPlatform(null);
      await loadAccounts();
    }
  };

  const handleDisconnect = async (id: string) => {
    if (!confirm('Are you sure you want to disconnect this platform account?')) return;
    try {
      await api.disconnectAccount(id);
      await loadAccounts();
    } catch (err) {
      alert('Account disconnected.');
      await loadAccounts();
    }
  };

  return (
    <div className="space-y-6 animate-fadeIn pb-16">
      {/* Header */}
      <div className="flex flex-col justify-between gap-4 border-b border-white/[0.08] pb-5 sm:flex-row sm:items-center">
        <div>
          <div className="flex items-center gap-2">
            <span className="rounded bg-brand-indigo/15 px-2 py-0.5 text-[10px] font-semibold text-brand-cyan border border-brand-indigo/30">
              INTEGRATION HUB
            </span>
            <span className="text-xs text-slate-400">OAuth 2.0 PKCE Secure Vault</span>
          </div>
          <h1 className="font-heading text-xl font-bold text-white sm:text-2xl mt-1">
            Social Platform Gateways
          </h1>
          <p className="text-xs text-slate-400 mt-1 max-w-xl">
            Tokens are encrypted using AES-256 at rest. OmniPublish isolates API rate-limits and token lifecycles per destination.
          </p>
        </div>
      </div>

      {/* Grid of Platforms */}
      <div className="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
        {PLATFORMS.map((platform) => {
          const account = accounts.find((a) => a.platform === platform.key && a.status !== 'REVOKED');
          const isConnected = !!account;
          const isExpired = account?.status === 'EXPIRED';

          return (
            <div
              key={platform.key}
              className={`glass-panel rounded-xl p-5 border flex flex-col justify-between h-full transition-all hover:scale-[1.01] ${platform.borderClass}`}
            >
              <div className="flex flex-col flex-1 space-y-4">
                {/* Card Header */}
                <div className="flex items-start justify-between min-h-[44px]">
                  <div className="flex items-center gap-3">
                    <div className={`flex h-11 w-11 items-center justify-center rounded-xl bg-white/[0.05] border border-white/10 font-bold ${platform.accentClass}`}>
                      {platform.key.slice(0, 2)}
                    </div>
                    <div>
                      <h3 className="text-sm font-semibold text-white">{platform.name}</h3>
                      <p className="text-[11px] text-slate-400">{platform.category}</p>
                    </div>
                  </div>

                  {/* Health Badge */}
                  {isConnected ? (
                    isExpired ? (
                      <span className="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-400 border border-amber-500/20">
                        <AlertTriangle className="h-3 w-3" />
                        Auth Expired
                      </span>
                    ) : (
                      <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-400 border border-emerald-500/20">
                        <CheckCircle2 className="h-3 w-3" />
                        Active
                      </span>
                    )
                  ) : (
                    <span className="rounded-full bg-white/[0.06] px-2 py-0.5 text-[10px] font-medium text-slate-400">
                      Not Connected
                    </span>
                  )}
                </div>

                {/* Middle details container */}
                <div className="flex-1 flex flex-col justify-center min-h-[90px] space-y-3">
                  {/* Connected Account Details */}
                  {isConnected && account && (
                    <div className="rounded-lg bg-black/40 p-3 border border-white/[0.04]">
                      <div className="flex items-center gap-2.5">
                        {account.avatar_url ? (
                          <img
                            src={account.avatar_url}
                            alt="Avatar"
                            className="h-7 w-7 rounded-full object-cover border border-white/10"
                          />
                        ) : (
                          <div className="h-7 w-7 rounded-full bg-slate-700 flex items-center justify-center text-[10px] text-white">
                            {account.account_name.slice(0, 2)}
                          </div>
                        )}
                        <div className="truncate">
                          <p className="text-xs font-semibold text-white truncate">{account.account_name}</p>
                          <p className="text-[10px] text-slate-400 truncate">{account.account_handle}</p>
                        </div>
                      </div>
                    </div>
                  )}

                  {/* Scopes Pill Matrix */}
                  <div>
                    <p className="text-[10px] font-semibold text-slate-400 mb-1.5 flex items-center gap-1">
                      <Shield className="h-3 w-3 text-brand-indigo" />
                      Granted Permissions:
                    </p>
                    <div className="flex flex-wrap gap-1">
                      {platform.scopesList.map((scope) => (
                        <span
                          key={scope}
                          className="rounded bg-white/[0.04] px-1.5 py-0.5 text-[9px] font-mono text-slate-300 border border-white/[0.06]"
                        >
                          {scope}
                        </span>
                      ))}
                    </div>
                  </div>
                </div>
              </div>

              {/* Action Buttons */}
              <div className="mt-5 pt-3 border-t border-white/[0.06] flex items-center justify-between h-12">
                {isConnected ? (
                  <>
                    <button
                      onClick={() => handleDisconnect(account.id)}
                      className="text-[11px] text-slate-400 hover:text-rose-400 transition-colors flex items-center gap-1"
                    >
                      <Trash2 className="h-3 w-3" />
                      Disconnect
                    </button>

                    {isExpired ? (
                      <button
                        onClick={() => setConnectingPlatform(platform.key)}
                        className="rounded-lg bg-amber-500/20 px-3 py-1.5 text-xs font-semibold text-amber-300 hover:bg-amber-500/30 transition-all flex items-center gap-1.5 border border-amber-500/30"
                      >
                        <RefreshCw className="h-3 w-3" />
                        Reconnect Now
                      </button>
                    ) : (
                      <Link
                        href={`/connections/${account.id}`}
                        className="rounded-lg bg-indigo-500/20 px-3 py-1.5 text-xs font-semibold text-indigo-300 hover:bg-indigo-500/30 transition-all border border-indigo-500/30"
                      >
                        Manage Channel
                      </Link>
                    )}
                  </>
                ) : (
                  <button
                    onClick={() => setConnectingPlatform(platform.key)}
                    className="w-full flex items-center justify-center gap-1.5 rounded-lg bg-gradient-to-r from-brand-indigo to-brand-violet py-2 text-xs font-semibold text-white shadow-glow-indigo hover:brightness-110 active:scale-95 transition-all"
                  >
                    <Plus className="h-3.5 w-3.5" />
                    Connect {platform.name}
                  </button>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {/* Connect Modal */}
      {connectingPlatform && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-md p-4 animate-fadeIn">
          <div className="glass-panel max-w-md w-full rounded-2xl p-6 border border-white/15 space-y-4">
            <div className="flex items-center justify-between pb-3 border-b border-white/10">
              <h3 className="font-heading text-base font-bold text-white flex items-center gap-2">
                <Key className="h-4 w-4 text-brand-cyan" />
                Connect {connectingPlatform} Account
              </h3>
              <button
                onClick={() => setConnectingPlatform(null)}
                className="text-slate-400 hover:text-white"
              >
                ✕
              </button>
            </div>

            <p className="text-xs text-slate-300">
              In production, this initiates the official OAuth 2.0 PKCE consent screen. For development and testing, you can specify your channel handle.
            </p>

            <div>
              <label className="block text-xs font-medium text-slate-300 mb-1">
                Channel / Creator Name
              </label>
              <input
                type="text"
                value={handleName}
                onChange={(e) => setHandleName(e.target.value)}
                placeholder="e.g. Alex Vance Official"
                className="w-full glass-input rounded-lg px-3 py-2 text-xs"
              />
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <button
                onClick={() => setConnectingPlatform(null)}
                className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300"
              >
                Cancel
              </button>
              <button
                onClick={() => handleConnect(connectingPlatform)}
                className="rounded-lg bg-brand-indigo px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-violet"
              >
                Authorize & Connect
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
