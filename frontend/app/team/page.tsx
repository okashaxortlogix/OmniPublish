'use client';

import React, { useState } from 'react';
import {
  Users,
  Plus,
  Mail,
  Shield,
  MoreVertical,
  CheckCircle2,
  AlertCircle,
  X,
  Trash2,
  UserCheck
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';

interface Member {
  id: string;
  name: string;
  email: string;
  role: 'Owner' | 'Admin' | 'Editor' | 'Viewer';
  status: 'Active' | 'Invited';
  lastActive: string;
  avatar: string;
}

export default function TeamPage() {
  const [members, setMembers] = useState<Member[]>([]);
  const [loading, setLoading] = useState(true);
  const [inviteModalOpen, setInviteModalOpen] = useState(false);
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteRole, setInviteRole] = useState<'Admin' | 'Editor' | 'Viewer'>('Editor');
  const [inviteMessage, setInviteMessage] = useState('');
  const [inviteSuccess, setInviteSuccess] = useState(false);
  const [inviting, setInviting] = useState(false);
  const [inviteError, setInviteError] = useState<string | null>(null);

  const loadMembers = async () => {
    try {
      setLoading(true);
      const data = await api.getWorkspaceMembers();
      if (Array.isArray(data)) {
        const mapped: Member[] = data.map((m) => {
          const roleNormalized = (m.role.charAt(0).toUpperCase() + m.role.slice(1).toLowerCase()) as Member['role'];
          const displayName = m.user?.name || m.user?.email?.split('@')[0] || 'Member';
          return {
            id: m.id,
            name: displayName,
            email: m.user?.email || '',
            role: roleNormalized,
            status: 'Active',
            lastActive: 'Active',
            avatar: `https://ui-avatars.com/api/?name=${encodeURIComponent(displayName)}&background=2563eb&color=fff&size=150`,
          };
        });
        setMembers(mapped);
      }
    } catch (err) {
      console.error('Failed to load workspace members:', err);
    } finally {
      setLoading(false);
    }
  };

  React.useEffect(() => {
    loadMembers();
  }, []);

  const handleInviteSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!inviteEmail.trim()) return;

    setInviting(true);
    setInviteError(null);
    try {
      await api.inviteWorkspaceMember(inviteEmail.trim(), inviteRole);
      setInviteSuccess(true);
      await loadMembers();
      setTimeout(() => {
        setInviteSuccess(false);
        setInviteModalOpen(false);
        setInviteEmail('');
      }, 1200);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Failed to invite member';
      setInviteError(msg);
    } finally {
      setInviting(false);
    }
  };

  const handleRemoveMember = async (id: string) => {
    if (confirm('Are you sure you want to remove this team member?')) {
      try {
        await api.removeWorkspaceMember(id);
        setMembers((prev) => prev.filter((m) => m.id !== id));
      } catch (err) {
        console.error('Failed to remove member:', err);
      }
    }
  };

  return (
    <AppShell pageTitle="Team Members">
      <div className="space-y-6">
        {/* Header matching Screenshot 12 */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Team Members</h1>
            <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
              Manage workspace access, permissions, and roles.
            </p>
          </div>

          <button
            onClick={() => setInviteModalOpen(true)}
            className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors"
          >
            <Plus className="h-3.5 w-3.5" />
            <span>Invite Member</span>
          </button>
        </div>

        {/* Members Table Card matching Screenshot 12 */}
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
              <tr>
                <th className="px-6 py-3.5">Name</th>
                <th className="px-4 py-3.5">Role</th>
                <th className="px-4 py-3.5">Status</th>
                <th className="px-4 py-3.5">Last Active</th>
                <th className="px-6 py-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {members.map((member) => (
                <tr key={member.id} className="hover:bg-slate-50/70 transition-colors">
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-3">
                      <img
                        src={member.avatar}
                        alt={member.name}
                        className="h-9 w-9 rounded-full object-cover border border-slate-200"
                      />
                      <div>
                        <h4 className="font-semibold text-slate-900">{member.name}</h4>
                        <p className="text-[11px] text-slate-500">{member.email}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-4">
                    <span
                      className={`inline-flex rounded-full px-2.5 py-0.5 text-[10px] font-semibold ${
                        member.role === 'Owner'
                          ? 'bg-blue-50 text-blue-700'
                          : member.role === 'Admin'
                          ? 'bg-purple-50 text-purple-700'
                          : member.role === 'Editor'
                          ? 'bg-amber-50 text-amber-700'
                          : 'bg-slate-100 text-slate-700'
                      }`}
                    >
                      {member.role}
                    </span>
                  </td>
                  <td className="px-4 py-4">
                    <span
                      className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ${
                        member.status === 'Active'
                          ? 'text-emerald-700'
                          : 'text-amber-600'
                      }`}
                    >
                      <span
                        className={`h-1.5 w-1.5 rounded-full ${
                          member.status === 'Active' ? 'bg-emerald-500' : 'bg-amber-500'
                        }`}
                      />
                      {member.status}
                    </span>
                  </td>
                  <td className="px-4 py-4 text-slate-500">{member.lastActive}</td>
                  <td className="px-6 py-4 text-right">
                    {member.role !== 'Owner' && (
                      <button
                        onClick={() => handleRemoveMember(member.id)}
                        className="p-1 rounded text-slate-400 hover:text-red-600"
                        title="Remove member"
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Invite Member Modal matching Section 16 */}
        {inviteModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4">
            <div className="w-full max-w-md rounded-xl border border-slate-200 bg-white p-6 shadow-xl space-y-4">
              <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                <div className="flex items-center gap-2">
                  <Mail className="h-4 w-4 text-blue-600" />
                  <h3 className="text-sm font-bold text-slate-900">Invite Team Member</h3>
                </div>
                <button
                  onClick={() => setInviteModalOpen(false)}
                  className="text-slate-400 hover:text-slate-700"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>

              {inviteSuccess ? (
                <div className="rounded-lg bg-emerald-50 border border-emerald-200 p-4 text-center space-y-2">
                  <CheckCircle2 className="h-6 w-6 text-emerald-600 mx-auto" />
                  <p className="text-xs font-semibold text-emerald-800">
                    Invitation sent successfully!
                  </p>
                </div>
              ) : (
                <form onSubmit={handleInviteSubmit} className="space-y-4">
                  {inviteError && (
                    <div className="rounded-lg bg-red-50 border border-red-200 p-2.5 text-xs text-red-700">
                      {inviteError}
                    </div>
                  )}

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Email Address
                    </label>
                    <input
                      type="email"
                      value={inviteEmail}
                      onChange={(e) => setInviteEmail(e.target.value)}
                      required
                      placeholder="colleague@company.com"
                      className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Role &amp; Permissions
                    </label>
                    <select
                      value={inviteRole}
                      onChange={(e) => setInviteRole(e.target.value as any)}
                      className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
                    >
                      <option value="Admin">Admin (Full publishing &amp; account settings)</option>
                      <option value="Editor">Editor (Upload, composer &amp; publish)</option>
                      <option value="Viewer">Viewer (Read-only access to analytics &amp; content)</option>
                    </select>
                  </div>

                  <div>
                    <label className="block text-xs font-semibold text-slate-700 mb-1">
                      Personal Note (Optional)
                    </label>
                    <textarea
                      rows={2}
                      value={inviteMessage}
                      onChange={(e) => setInviteMessage(e.target.value)}
                      placeholder="Hey, join our OmniPublish workspace..."
                      className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
                    />
                  </div>

                  <div className="flex justify-end gap-2 pt-2 border-t border-slate-100">
                    <button
                      type="button"
                      onClick={() => setInviteModalOpen(false)}
                      className="rounded-lg border border-slate-200 px-3.5 py-1.5 text-xs font-medium text-slate-600 hover:bg-slate-50"
                    >
                      Cancel
                    </button>
                    <button
                      type="submit"
                      disabled={inviting}
                      className="rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
                    >
                      {inviting ? 'Inviting...' : 'Send Invitation'}
                    </button>
                  </div>
                </form>
              )}
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
