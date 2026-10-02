'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { Layers, CheckCircle2 } from 'lucide-react';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [submitted, setSubmitted] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitted(true);
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-8 shadow-sm space-y-6">
        {/* Brand */}
        <div className="flex flex-col items-center text-center space-y-2">
          <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-blue-600 text-white shadow-sm font-bold">
            <Layers className="h-6 w-6" />
          </div>
          <h1 className="text-xl font-bold text-slate-900">Reset Your Password</h1>
          <p className="text-xs text-slate-500 max-w-xs">
            Enter your email and we&apos;ll send you a link to reset your password.
          </p>
        </div>

        {submitted ? (
          <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-center space-y-2">
            <div className="flex justify-center text-emerald-600">
              <CheckCircle2 className="h-6 w-6" />
            </div>
            <p className="text-xs font-semibold text-emerald-800">Password reset link sent!</p>
            <p className="text-[11px] text-emerald-600">
              Check your inbox at <span className="font-semibold">{email}</span>.
            </p>
            <div className="pt-2">
              <Link href="/login" className="text-xs font-semibold text-blue-600 hover:underline">
                Back to login
              </Link>
            </div>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1">Email address</label>
              <input
                type="email"
                required
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                placeholder="you@example.com"
                className="w-full saas-input px-3 py-2 text-xs"
              />
            </div>

            <button
              type="submit"
              className="w-full py-2.5 px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs shadow-sm transition-colors"
            >
              Send Reset Link
            </button>

            <div className="text-center pt-2">
              <Link href="/login" className="text-xs text-slate-500 hover:text-slate-800 hover:underline">
                Back to login
              </Link>
            </div>
          </form>
        )}
      </div>
    </div>
  );
}
