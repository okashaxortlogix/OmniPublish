'use client';

import React from 'react';
import Link from 'next/link';
import {
  CreditCard,
  CheckCircle2,
  Download,
  Zap,
  ArrowUpRight,
  ShieldCheck
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

export default function BillingSettingsPage() {
  const tabs = [
    { id: 'general', name: 'General', href: '/settings/workspace' },
    { id: 'profile', name: 'Profile', href: '/settings/profile' },
    { id: 'security', name: 'Security', href: '/settings/security' },
    { id: 'billing', name: 'Billing', href: '/settings/billing' },
    { id: 'audit', name: 'Audit Logs', href: '/settings/audit-logs' },
  ];

  return (
    <AppShell pageTitle="Billing & Subscription">
      <div className="max-w-4xl mx-auto space-y-6">
        <div>
          <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Billing &amp; Subscription</h1>
          <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
            Manage your workspace plan, account limits, and payment methods.
          </p>
        </div>

        <div className="flex border-b border-slate-200 bg-white rounded-t-xl px-4 gap-2">
          {tabs.map((tab) => {
            const isActive = tab.id === 'billing';
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

        {/* Current Plan Card matching Screenshot 19 */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
          <div className="space-y-1.5">
            <div className="flex items-center gap-2">
              <span className="rounded bg-blue-50 px-2 py-0.5 text-xs font-bold text-blue-700">
                CURRENT PLAN
              </span>
              <span className="text-xs text-slate-400">• Renews on May 1, 2026</span>
            </div>
            <h2 className="text-2xl font-bold text-slate-900">Pro Plan — $49/month</h2>
            <p className="text-xs text-slate-600">
              Unlimited multi-platform video uploads, priority worker queues, and AI assistant.
            </p>
          </div>

          <div className="flex items-center gap-3">
            <button
              type="button"
              className="rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
            >
              Upgrade Plan
            </button>
            <button
              type="button"
              className="rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
            >
              Change Billing Cycle
            </button>
          </div>
        </div>

        {/* Usage Breakdown Grid */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
          <h3 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-3">
            Workspace Resource Usage
          </h3>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            {/* Storage */}
            <div className="space-y-1.5">
              <div className="flex justify-between text-xs font-semibold">
                <span className="text-slate-700">Storage Used</span>
                <span className="text-slate-900 font-mono">45.2 GB / 100 GB (45%)</span>
              </div>
              <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                <div className="h-full bg-blue-600 rounded-full w-[45%]" />
              </div>
            </div>

            {/* Connected Accounts */}
            <div className="space-y-1.5">
              <div className="flex justify-between text-xs font-semibold">
                <span className="text-slate-700">Connected Social Accounts</span>
                <span className="text-slate-900 font-mono">5 / 10 (50%)</span>
              </div>
              <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                <div className="h-full bg-purple-600 rounded-full w-[50%]" />
              </div>
            </div>

            {/* Publishing Volume */}
            <div className="space-y-1.5">
              <div className="flex justify-between text-xs font-semibold">
                <span className="text-slate-700">Monthly Video Dispatches</span>
                <span className="text-slate-900 font-mono">142 / 500 (28%)</span>
              </div>
              <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                <div className="h-full bg-emerald-600 rounded-full w-[28%]" />
              </div>
            </div>

            {/* Team Members */}
            <div className="space-y-1.5">
              <div className="flex justify-between text-xs font-semibold">
                <span className="text-slate-700">Team Seats</span>
                <span className="text-slate-900 font-mono">4 / 10 (40%)</span>
              </div>
              <div className="h-2 w-full bg-slate-100 rounded-full overflow-hidden">
                <div className="h-full bg-amber-500 rounded-full w-[40%]" />
              </div>
            </div>
          </div>
        </div>

        {/* Payment Method Card matching Screenshot 19 */}
        <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4">
          <div className="flex items-center justify-between border-b border-slate-100 pb-3">
            <h3 className="text-sm font-bold text-slate-900">Payment Method</h3>
            <button className="text-xs font-semibold text-blue-600 hover:underline">
              Update Card
            </button>
          </div>

          <div className="flex items-center justify-between rounded-lg border border-slate-200 p-4">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-12 items-center justify-center rounded bg-slate-100 font-bold text-blue-800 text-xs">
                VISA
              </div>
              <div>
                <p className="text-xs font-bold text-slate-900">Visa ending in 4242</p>
                <p className="text-[11px] text-slate-400">Expires 12/28 • Default payment card</p>
              </div>
            </div>

            <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
              Active
            </span>
          </div>
        </div>

        {/* Invoice History */}
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div className="border-b border-slate-200 px-6 py-4">
            <h3 className="text-sm font-bold text-slate-900">Invoice History</h3>
          </div>

          <table className="w-full text-left text-xs">
            <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 font-semibold">
              <tr>
                <th className="px-6 py-3">Date</th>
                <th className="px-4 py-3">Plan</th>
                <th className="px-4 py-3 font-mono">Amount</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-6 py-3 text-right">Receipt</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {[
                { date: 'Apr 01, 2026', desc: 'Pro Plan Monthly', amount: '$49.00', status: 'Paid' },
                { date: 'Mar 01, 2026', desc: 'Pro Plan Monthly', amount: '$49.00', status: 'Paid' },
                { date: 'Feb 01, 2026', desc: 'Pro Plan Monthly', amount: '$49.00', status: 'Paid' },
              ].map((inv, idx) => (
                <tr key={idx} className="hover:bg-slate-50">
                  <td className="px-6 py-3.5 text-slate-600">{inv.date}</td>
                  <td className="px-4 py-3.5 font-medium text-slate-900">{inv.desc}</td>
                  <td className="px-4 py-3.5 font-mono text-slate-800">{inv.amount}</td>
                  <td className="px-4 py-3.5">
                    <span className="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                      {inv.status}
                    </span>
                  </td>
                  <td className="px-6 py-3.5 text-right">
                    <button
                      type="button"
                      className="inline-flex items-center gap-1 text-blue-600 hover:underline font-semibold"
                    >
                      <Download className="h-3 w-3" />
                      <span>PDF</span>
                    </button>
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
