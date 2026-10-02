'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import {
  Shield,
  Key,
  Smartphone,
  Laptop,
  CheckCircle2,
  AlertTriangle,
  LogOut,
  Clock
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

export default function SecuritySettingsPage() {
  const [twoFactorEnabled, setTwoFactorEnabled] = useState(false);
  const [revokedSession, setRevokedSession] = useState<string | null>(null);

  const tabs = [
    { id: 'general', name: 'General', href: '/settings/workspace' },
    { id: 'profile', name: 'Profile', href: '/settings/profile' },
    { id: 'security', name: 'Security', href: '/settings/security' },
    { id: 'billing', name: 'Billing', href: '/settings/billing' },
    { id: 'audit', name: 'Audit Logs', href: '/settings/audit-logs' },
  ];

  return (
    <AppShell pageTitle="Security Settings">
      <div className="max-w-4xl mx-auto space-y-6">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Security &amp; Access Controls</h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
            Manage your account authentication, multi-factor authorization, and active sessions.
          </p>
        </div>

        <div className="flex border-b border-slate-200 bg-white rounded-t-xl px-4 gap-2">
          {tabs.map((tab) => {
            const isActive = tab.id === 'security';
            return (
              <Link
                key={tab.id}
                href={tab.href}
                className={`py-3 px-3 text-xs font-semibold border-b-2 transition-colors ${
                  isActive
                    ? 'border-blue-600 text-blue-600'
                    : 'border-transparent text-slate-500 hover:text-slate-800'
                }`}
              >
                {tab.name}
              </Link>
            );
          })}
        </div>

        {/* Two-Factor Authentication Card */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
          <div className="flex items-start justify-between">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-50 text-blue-600">
                <Smartphone className="h-5 w-5" />
              </div>
              <div>
                <h3 className="text-sm font-bold text-slate-900">Two-Factor Authentication (2FA)</h3>
                <p className="text-xs text-slate-500">
                  Protect your OmniPublish account with an authenticator app (TOTP).
                </p>
              </div>
            </div>

            <button
              onClick={() => setTwoFactorEnabled(!twoFactorEnabled)}
              className={`rounded-lg px-3.5 py-1.5 text-xs font-semibold transition-colors ${
                twoFactorEnabled
                  ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                  : 'bg-blue-600 text-white hover:bg-blue-700'
              }`}
            >
              {twoFactorEnabled ? 'Enabled' : 'Enable 2FA'}
            </button>
          </div>
        </div>

        {/* Active Sessions Card */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
              <h3 className="text-sm font-bold text-slate-900">Active Sessions</h3>
              <p className="text-xs text-slate-500">Devices currently logged into your account</p>
            </div>

            <button
              type="button"
              className="text-xs font-semibold text-red-600 hover:underline flex items-center gap-1"
            >
              <LogOut className="h-3.5 w-3.5" />
              <span>Sign out of all sessions</span>
            </button>
          </div>

          <div className="space-y-3">
            <div className="flex items-center justify-between rounded-lg border border-slate-200 p-3.5">
              <div className="flex items-center gap-3">
                <Laptop className="h-5 w-5 text-slate-600" />
                <div>
                  <div className="flex items-center gap-2">
                    <span className="text-xs font-bold text-slate-900">Chrome on Windows</span>
                    <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">
                      Current Session
                    </span>
                  </div>
                  <p className="text-[11px] text-slate-400">IP: 192.168.1.45 • Active now</p>
                </div>
              </div>
            </div>

            <div className="flex items-center justify-between rounded-lg border border-slate-200 p-3.5">
              <div className="flex items-center gap-3">
                <Smartphone className="h-5 w-5 text-slate-600" />
                <div>
                  <span className="text-xs font-bold text-slate-900">Safari on iPhone 15 Pro</span>
                  <p className="text-[11px] text-slate-400">IP: 73.189.20.12 • Active 2 days ago</p>
                </div>
              </div>

              {revokedSession === 'iphone' ? (
                <span className="text-xs text-slate-400">Revoked</span>
              ) : (
                <button
                  onClick={() => setRevokedSession('iphone')}
                  className="rounded border border-slate-200 px-2.5 py-1 text-xs text-slate-600 hover:bg-slate-50"
                >
                  Revoke
                </button>
              )}
            </div>
          </div>
        </div>

        {/* Security & Login History */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-3">
          <h3 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-3">
            Recent Security Events
          </h3>

          <div className="space-y-2 text-xs">
            {[
              { event: 'Successful Login', ip: '192.168.1.45', time: 'Today at 02:40 AM', status: 'Success' },
              { event: 'Password Changed', ip: '192.168.1.45', time: 'Yesterday at 08:12 PM', status: 'Success' },
              { event: 'New OAuth Token Stored (YouTube)', ip: '192.168.1.45', time: 'Apr 26 at 11:20 AM', status: 'Encrypted' },
            ].map((ev, idx) => (
              <div key={idx} className="flex items-center justify-between py-1.5 border-b border-slate-50 last:border-0">
                <div>
                  <span className="font-semibold text-slate-800">{ev.event}</span>
                  <span className="text-slate-400 ml-2 font-mono text-[11px]">{ev.ip}</span>
                </div>
                <div className="flex items-center gap-3">
                  <span className="text-slate-400 text-[11px]">{ev.time}</span>
                  <span className="rounded bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                    {ev.status}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </AppShell>
  );
}
