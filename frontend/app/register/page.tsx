'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { Layers, ArrowRight, CheckCircle2 } from 'lucide-react';

export default function RegisterPage() {
  const router = useRouter();
  const [fullName, setFullName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [workspaceName, setWorkspaceName] = useState('');
  const [terms, setTerms] = useState(true);
  const [loading, setLoading] = useState(false);

  const handleRegister = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);

    try {
      const res = await fetch('http://127.0.0.1:8000/api/v1/auth/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({
          name: fullName,
          email,
          password,
          workspaceName: workspaceName || `${fullName}'s Studio`,
        }),
      });

      router.push('/dashboard');
    } catch (err) {
      router.push('/dashboard');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen bg-slate-50">
      {/* Left Column */}
      <div className="hidden lg:flex lg:w-1/2 flex-col justify-between bg-gradient-to-br from-blue-50 via-indigo-50/40 to-slate-100 p-12 border-r border-slate-200">
        <div className="flex items-center gap-2.5">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white shadow-sm font-bold">
            <Layers className="h-5 w-5" />
          </div>
          <span className="font-bold text-xl text-slate-900 tracking-tight">
            Omni<span className="text-blue-600">Publish</span>
          </span>
        </div>

        <div className="max-w-md space-y-6">
          <h1 className="text-4xl font-extrabold tracking-tight text-slate-900 leading-tight">
            Your Content. <br />
            <span className="text-blue-600">Everywhere.</span>
          </h1>
          <p className="text-sm text-slate-600 leading-relaxed">
            Join thousands of creators, agencies, and brands scaling their audience effortlessly across video platforms.
          </p>

          <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm space-y-3">
            <div className="flex items-center gap-2 text-xs font-semibold text-slate-800">
              <CheckCircle2 className="h-4 w-4 text-emerald-600" />
              <span>Full Access 14-Day Pro Trial</span>
            </div>
            <p className="text-xs text-slate-500">
              Connect unlimited accounts across YouTube, TikTok, and Instagram with zero credit card required.
            </p>
          </div>
        </div>

        <div className="text-xs text-slate-400">
          © 2026 OmniPublish Technologies Inc.
        </div>
      </div>

      {/* Right Column: Register Form */}
      <div className="flex flex-1 flex-col justify-center px-6 py-12 sm:px-12 lg:px-16 bg-white">
        <div className="mx-auto w-full max-w-sm space-y-6">
          <div className="space-y-1">
            <h2 className="text-2xl font-bold tracking-tight text-slate-900">Create Your Account</h2>
            <p className="text-xs text-slate-500">Join thousands of creators and businesses</p>
          </div>

          <form onSubmit={handleRegister} className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1">Full name</label>
              <input
                type="text"
                required
                value={fullName}
                onChange={(e) => setFullName(e.target.value)}
                placeholder="John Doe"
                className="w-full saas-input px-3 py-2 text-xs"
              />
            </div>

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

            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1">Workspace name</label>
              <input
                type="text"
                value={workspaceName}
                onChange={(e) => setWorkspaceName(e.target.value)}
                placeholder="e.g. Acme Media Studio"
                className="w-full saas-input px-3 py-2 text-xs"
              />
            </div>

            <div>
              <label className="block text-xs font-medium text-slate-700 mb-1">Password</label>
              <input
                type="password"
                required
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="••••••••"
                className="w-full saas-input px-3 py-2 text-xs"
              />
            </div>

            <div className="flex items-center">
              <input
                id="terms"
                type="checkbox"
                required
                checked={terms}
                onChange={(e) => setTerms(e.target.checked)}
                className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
              />
              <label htmlFor="terms" className="ml-2 block text-xs text-slate-600">
                I agree to the <a href="#" className="text-blue-600 underline">Terms</a> and <a href="#" className="text-blue-600 underline">Privacy Policy</a>
              </label>
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full flex justify-center items-center py-2.5 px-4 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold text-xs shadow-sm transition-colors disabled:opacity-50"
            >
              {loading ? 'Creating Account...' : 'Create Account'}
            </button>
          </form>

          <div className="text-center text-xs text-slate-500">
            Already have an account?{' '}
            <Link href="/login" className="font-semibold text-blue-600 hover:text-blue-700 hover:underline">
              Sign in
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}
