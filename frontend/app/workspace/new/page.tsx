'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  Layers,
  Building,
  ArrowRight,
  CheckCircle2,
  FolderPlus
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

export default function CreateWorkspacePage() {
  const router = useRouter();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [category, setCategory] = useState('Creator & Media Company');
  const [timezone, setTimezone] = useState('UTC');
  const [creating, setCreating] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setCreating(true);
    setTimeout(() => {
      setCreating(false);
      router.push('/connections');
    }, 600);
  };

  return (
    <AppShell pageTitle="Create New Workspace">
      <div className="max-w-2xl mx-auto space-y-6">
        {/* Header matching Screenshot 16 */}
        <div className="text-center space-y-2">
          <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-blue-600 text-white font-bold mx-auto shadow-sm">
            <FolderPlus className="h-6 w-6" />
          </div>
          <h1 className="text-2xl font-bold text-slate-900">Create New Workspace</h1>
          <p className="text-xs text-slate-500 max-w-sm mx-auto">
            Workspaces isolate social connections, media assets, team permissions, and analytics.
          </p>
        </div>

        <form onSubmit={handleSubmit} className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1">
              Workspace Name *
            </label>
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
              placeholder="e.g. Acme Media Production"
              className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
            />
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 mb-1">
              Description (Optional)
            </label>
            <textarea
              rows={3}
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder="Brief summary of this team or channel portfolio..."
              className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
            />
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Industry / Category
              </label>
              <select
                value={category}
                onChange={(e) => setCategory(e.target.value)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
              >
                <option value="Creator & Media Company">Creator &amp; Media Company</option>
                <option value="SaaS & Tech Startup">SaaS &amp; Tech Startup</option>
                <option value="Marketing Agency">Marketing Agency</option>
                <option value="E-commerce & Brand">E-commerce &amp; Brand</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">
                Timezone
              </label>
              <select
                value={timezone}
                onChange={(e) => setTimezone(e.target.value)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
              >
                <option value="UTC">UTC (Coordinated Universal Time)</option>
                <option value="America/New_York">America/New_York (EST)</option>
                <option value="America/Los_Angeles">America/Los_Angeles (PST)</option>
                <option value="Europe/London">Europe/London (GMT)</option>
              </select>
            </div>
          </div>

          <div className="flex items-center justify-between pt-4 border-t border-slate-100">
            <Link
              href="/dashboard"
              className="text-xs font-semibold text-slate-500 hover:text-slate-700"
            >
              Cancel
            </Link>

            <button
              type="submit"
              disabled={creating}
              className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
            >
              <span>{creating ? 'Creating Workspace...' : 'Create Workspace'}</span>
              <ArrowRight className="h-4 w-4" />
            </button>
          </div>
        </form>
      </div>
    </AppShell>
  );
}
