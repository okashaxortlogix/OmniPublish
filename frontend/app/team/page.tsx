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
  const [members, setMembers] = useState<Member[]>([
    {
      id: '1',
      name: 'John Doe',
      email: 'john@company.com',
      role: 'Owner',
      status: 'Active',
      lastActive: 'Just now',
      avatar: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150',
    },
    {
      id: '2',
      name: 'Sarah Khan',
      email: 'sarah@company.com',
      role: 'Admin',
      status: 'Active',
      lastActive: '2 hours ago',
      avatar: 'https://images.unsplash.com/photo-1494790108377-be9c29b29330?w=150',
    },
    {
      id: '3',
      name: 'Ali Raza',
      email: 'ali@company.com',
      role: 'Editor',
      status: 'Active',
      lastActive: 'Yesterday',
      avatar: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=150',
    },
    {
      id: '4',
      name: 'Ayesha Malik',
      email: 'ayesha@company.com',
      role: 'Viewer',
      status: 'Invited',
      lastActive: 'Invited 3d ago',
      avatar: 'https://images.unsplash.com/photo-1438761681033-6461ffad8d80?w=150',
    },
  ]);

  const [inviteModalOpen, setInviteModalOpen] = useState(false);
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteRole, setInviteRole] = useState<'Admin' | 'Editor' | 'Viewer'>('Editor');
  const [inviteMessage, setInviteMessage] = useState('');
  const [inviteSuccess, setInviteSuccess] = useState(false);

  const handleInviteSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!inviteEmail.trim()) return;

    const newMember: Member = {
      id: Date.now().toString(),
      name: inviteEmail.split('@')[0],
      email: inviteEmail,
      role: inviteRole,
      status: 'Invited',
      lastActive: 'Just now',
      avatar: 'https://images.unsplash.com/photo-1535713875002-d1d0cf377fde?w=150',
    };

    setMembers([...members, newMember]);
    setInviteSuccess(true);
    setTimeout(() => {
      setInviteSuccess(false);
      setInviteModalOpen(false);
      setInviteEmail('');
    }, 1000);
  };

  const handleRemoveMember = (id: string) => {
    if (confirm('Are you sure you want to remove this team member?')) {
      setMembers(members.filter((m) => m.id !== id));
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
                      className="rounded-lg bg-blue-600 px-4 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
                    >
                      Send Invitation
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
