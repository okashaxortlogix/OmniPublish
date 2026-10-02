'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import {
  Settings,
  Building,
  Image as ImageIcon,
  Globe,
  Bell,
  CheckCircle2,
  Save,
  Check
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

export default function WorkspaceSettingsPage() {
  const [activeTab, setActiveTab] = useState('general');
  const [saved, setSaved] = useState(false);

  const [workspaceName, setWorkspaceName] = useState('OmniPublish Studio');
  const [description, setDescription] = useState('Central content creation & distribution hub for our SaaS brand.');
  const [timezone, setTimezone] = useState('UTC');
  const [defaultLang, setDefaultLang] = useState('English (US)');

  const handleSave = (e: React.FormEvent) => {
    e.preventDefault();
    setSaved(true);
    setTimeout(() => setSaved(false), 2000);
  };

  const tabs = [
    { id: 'general', name: 'General', href: '/settings/workspace' },
    { id: 'profile', name: 'Profile', href: '/settings/profile' },
    { id: 'security', name: 'Security', href: '/settings/security' },
    { id: 'billing', name: 'Billing', href: '/settings/billing' },
    { id: 'audit', name: 'Audit Logs', href: '/settings/audit-logs' },
  ];

  return (
    <AppShell pageTitle="Workspace Settings">
      <div className="max-w-4xl mx-auto space-y-6">
        {/* Header */}
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Workspace Settings</h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
            Configure tenant parameters, defaults, and team preferences.
          </p>
        </div>

        {/* Navigation Tabs matching Screenshot 13 */}
        <div className="flex border-b border-slate-200 bg-white rounded-t-xl px-4 gap-2">
          {tabs.map((tab) => {
            const isActive = tab.id === 'general';
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

        {/* Settings Form Card */}
        <form onSubmit={handleSave} className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
          <div className="space-y-4">
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Workspace Name
              </label>
              <input
                type="text"
                value={workspaceName}
                onChange={(e) => setWorkspaceName(e.target.value)}
                required
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Workspace Description
              </label>
              <textarea
                rows={3}
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
              />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Primary Timezone
                </label>
                <select
                  value={timezone}
                  onChange={(e) => setTimezone(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                >
                  <option value="UTC">UTC (Coordinated Universal Time)</option>
                  <option value="America/New_York">America/New_York (EST)</option>
                  <option value="America/Los_Angeles">America/Los_Angeles (PST)</option>
                  <option value="Europe/London">Europe/London (GMT/BST)</option>
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Default Language
                </label>
                <select
                  value={defaultLang}
                  onChange={(e) => setDefaultLang(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
                >
                  <option value="English (US)">English (US)</option>
                  <option value="English (UK)">English (UK)</option>
                  <option value="Spanish">Spanish</option>
                  <option value="French">French</option>
                  <option value="German">German</option>
                </select>
              </div>
            </div>

            {/* Workspace Logo */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-2">
                Workspace Logo
              </label>
              <div className="flex items-center gap-4">
                <div className="flex h-14 w-14 items-center justify-center rounded-xl bg-blue-600 text-white font-bold text-xl shadow-sm">
                  O
                </div>
                <div>
                  <button
                    type="button"
                    className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                  >
                    Change Logo
                  </button>
                  <p className="text-[11px] text-slate-400 mt-1">Recommended: 256x256 PNG or SVG</p>
                </div>
              </div>
            </div>
          </div>

          <div className="flex items-center justify-between pt-4 border-t border-slate-100">
            {saved ? (
              <span className="flex items-center gap-1.5 text-xs text-emerald-600 font-semibold">
                <CheckCircle2 className="h-4 w-4" />
                Settings saved successfully!
              </span>
            ) : (
              <span className="text-xs text-slate-400">All changes apply immediately to this workspace.</span>
            )}

            <button
              type="submit"
              className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
            >
              <Save className="h-3.5 w-3.5" />
              <span>Save Changes</span>
            </button>
          </div>
        </form>
      </div>
    </AppShell>
  );
}
