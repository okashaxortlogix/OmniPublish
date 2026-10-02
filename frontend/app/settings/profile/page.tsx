'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import {
  User,
  Mail,
  Camera,
  Save,
  CheckCircle2,
  Lock,
  Check
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

export default function ProfileSettingsPage() {
  const [name, setName] = useState('John Doe');
  const [email, setEmail] = useState('john@company.com');
  const [role, setRole] = useState('Content Creator & Digital Marketer');
  const [saved, setSaved] = useState(false);

  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [pwSaved, setPwSaved] = useState(false);

  const handleSaveProfile = (e: React.FormEvent) => {
    e.preventDefault();
    setSaved(true);
    setTimeout(() => setSaved(false), 2000);
  };

  const handleSavePassword = (e: React.FormEvent) => {
    e.preventDefault();
    setPwSaved(true);
    setTimeout(() => {
      setPwSaved(false);
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    }, 2000);
  };

  const tabs = [
    { id: 'general', name: 'General', href: '/settings/workspace' },
    { id: 'profile', name: 'Profile', href: '/settings/profile' },
    { id: 'security', name: 'Security', href: '/settings/security' },
    { id: 'billing', name: 'Billing', href: '/settings/billing' },
    { id: 'audit', name: 'Audit Logs', href: '/settings/audit-logs' },
  ];

  return (
    <AppShell pageTitle="Profile Settings">
      <div className="max-w-4xl mx-auto space-y-6">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Profile Settings</h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
            Manage your personal profile, credentials, and notification preferences.
          </p>
        </div>

        <div className="flex border-b border-slate-200 bg-white rounded-t-xl px-4 gap-2">
          {tabs.map((tab) => {
            const isActive = tab.id === 'profile';
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

        {/* Profile Details Form matching Screenshot 20 */}
        <form onSubmit={handleSaveProfile} className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
          <h3 className="text-sm font-semibold text-slate-900 border-b border-slate-100 pb-3">
            Personal Details
          </h3>

          <div className="flex items-center gap-5">
            <div className="relative">
              <img
                src="https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=200"
                alt="Avatar"
                className="h-20 w-20 rounded-full object-cover border-2 border-slate-200"
              />
              <button
                type="button"
                className="absolute bottom-0 right-0 rounded-full bg-blue-600 p-1.5 text-white hover:bg-blue-700 shadow"
                title="Change Avatar"
              >
                <Camera className="h-3.5 w-3.5" />
              </button>
            </div>
            <div>
              <h4 className="text-sm font-bold text-slate-900">{name}</h4>
              <p className="text-xs text-slate-500">{email}</p>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">Full Name</label>
              <input
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">Email Address</label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
              />
            </div>

            <div className="md:col-span-2">
              <label className="block text-xs font-semibold text-slate-700 mb-1">Bio / Role Description</label>
              <input
                type="text"
                value={role}
                onChange={(e) => setRole(e.target.value)}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs text-slate-900 focus:border-blue-600 focus:outline-none"
              />
            </div>
          </div>

          <div className="flex justify-end pt-3 border-t border-slate-100">
            <button
              type="submit"
              className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
            >
              <Save className="h-3.5 w-3.5" />
              <span>{saved ? 'Saved!' : 'Save Changes'}</span>
            </button>
          </div>
        </form>

        {/* Change Password Form */}
        <form onSubmit={handleSavePassword} className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
          <h3 className="text-sm font-semibold text-slate-900 border-b border-slate-100 pb-3">
            Change Password
          </h3>

          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">Current Password</label>
              <input
                type="password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                placeholder="••••••••"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">New Password</label>
              <input
                type="password"
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder="••••••••"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
              />
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 mb-1">Confirm New Password</label>
              <input
                type="password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                placeholder="••••••••"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
              />
            </div>
          </div>

          <div className="flex justify-end pt-2">
            <button
              type="submit"
              className="rounded-lg border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
            >
              {pwSaved ? 'Password Updated!' : 'Update Password'}
            </button>
          </div>
        </form>
      </div>
    </AppShell>
  );
}
