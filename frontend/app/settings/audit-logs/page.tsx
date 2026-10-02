'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import {
  History,
  Filter,
  Search,
  Shield,
  CheckCircle2,
  AlertCircle
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

interface AuditLogEntry {
  id: string;
  dateTime: string;
  user: string;
  action: string;
  resource: string;
  details: string;
  result: 'Success' | 'Failed';
}

export default function AuditLogsPage() {
  const [selectedAction, setSelectedAction] = useState('ALL');

  const logs: AuditLogEntry[] = [
    {
      id: '1',
      dateTime: 'Apr 28, 2026 10:24 AM',
      user: 'John Doe',
      action: 'PUBLISH_REQUESTED',
      resource: 'Batch #OP-2026-00042',
      details: 'Target: YouTube, TikTok, Instagram',
      result: 'Success',
    },
    {
      id: '2',
      dateTime: 'Apr 28, 2026 09:15 AM',
      user: 'Sarah Khan',
      action: 'ACCOUNT_CONNECTED',
      resource: 'TikTok Profile',
      details: '@tiktokcreator via OAuth 2.0',
      result: 'Success',
    },
    {
      id: '3',
      dateTime: 'Apr 27, 2026 04:30 PM',
      user: 'Ali Raza',
      action: 'CONTENT_UPDATED',
      resource: 'cnt-1',
      details: 'Updated global video description & hashtags',
      result: 'Success',
    },
    {
      id: '4',
      dateTime: 'Apr 26, 2026 02:40 PM',
      user: 'Ayesha Malik',
      action: 'MEMBER_ADDED',
      resource: 'Team',
      details: 'Invited as Viewer',
      result: 'Success',
    },
    {
      id: '5',
      dateTime: 'Apr 25, 2026 11:12 AM',
      user: 'SYSTEM',
      action: 'LOGIN',
      resource: 'Session',
      details: 'Web session initialized (IP: 192.168.1.45)',
      result: 'Success',
    },
  ];

  const tabs = [
    { id: 'general', name: 'General', href: '/settings/workspace' },
    { id: 'profile', name: 'Profile', href: '/settings/profile' },
    { id: 'security', name: 'Security', href: '/settings/security' },
    { id: 'billing', name: 'Billing', href: '/settings/billing' },
    { id: 'audit', name: 'Audit Logs', href: '/settings/audit-logs' },
  ];

  return (
    <AppShell pageTitle="Audit Logs">
      <div className="max-w-5xl mx-auto space-y-6">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Audit Logs</h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
            Tamper-evident record of all workspace activities, credential operations, and publish attempts.
          </p>
        </div>

        <div className="flex border-b border-slate-200 bg-white rounded-t-xl px-4 gap-2">
          {tabs.map((tab) => {
            const isActive = tab.id === 'audit';
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

        {/* Audit Logs Table matching Screenshot 14 */}
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div className="flex items-center justify-between border-b border-slate-200 px-6 py-3.5 bg-slate-50/50">
            <div className="flex items-center gap-3">
              <span className="text-xs font-semibold text-slate-700">Filter:</span>
              <select
                value={selectedAction}
                onChange={(e) => setSelectedAction(e.target.value)}
                className="rounded-lg border border-slate-200 bg-white px-3 py-1 text-xs text-slate-700 focus:outline-none"
              >
                <option value="ALL">All Actions</option>
                <option value="PUBLISH_REQUESTED">PUBLISH_REQUESTED</option>
                <option value="ACCOUNT_CONNECTED">ACCOUNT_CONNECTED</option>
                <option value="CONTENT_UPDATED">CONTENT_UPDATED</option>
                <option value="MEMBER_ADDED">MEMBER_ADDED</option>
              </select>
            </div>

            <span className="text-xs text-slate-400">Last 30 days</span>
          </div>

          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
              <tr>
                <th className="px-6 py-3.5">Date &amp; Time</th>
                <th className="px-4 py-3.5">User</th>
                <th className="px-4 py-3.5">Action</th>
                <th className="px-6 py-3.5">Safe Metadata / Details</th>
                <th className="px-4 py-3.5 text-right">Result</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {logs.map((log) => (
                <tr key={log.id} className="hover:bg-slate-50/70 transition-colors">
                  <td className="px-6 py-3.5 font-mono text-slate-500">{log.dateTime}</td>
                  <td className="px-4 py-3.5 font-medium text-slate-900">{log.user}</td>
                  <td className="px-4 py-3.5">
                    <span className="inline-block rounded bg-slate-100 px-2 py-0.5 font-mono text-[10px] font-semibold text-slate-700">
                      {log.action}
                    </span>
                  </td>
                  <td className="px-6 py-3.5 text-slate-600">
                    <span className="font-semibold text-slate-800">{log.resource}:</span>{' '}
                    <span>{log.details}</span>
                  </td>
                  <td className="px-4 py-3.5 text-right">
                    <span className="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                      {log.result}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </AppShell>
  );
}
