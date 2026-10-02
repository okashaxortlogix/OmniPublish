'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  Layers,
  ArrowRight,
  CheckCircle2,
  Building,
  Share2,
  UploadCloud,
  PenTool,
  Check,
  ChevronRight
} from 'lucide-react';

export default function OnboardingPage() {
  const router = useRouter();
  const [currentStep, setCurrentStep] = useState(1);

  // Form states
  const [workspaceName, setWorkspaceName] = useState('My Creator Studio');
  const [selectedPlatforms, setSelectedPlatforms] = useState<string[]>(['YouTube', 'TikTok']);

  const steps = [
    { num: 1, title: 'Welcome to OmniPublish' },
    { num: 2, title: 'Name Your Workspace' },
    { num: 3, title: 'Connect Social Destinations' },
    { num: 4, title: 'Ready to Publish' },
  ];

  const handleNext = () => {
    if (currentStep < 4) {
      setCurrentStep(currentStep + 1);
    } else {
      router.push('/dashboard');
    }
  };

  const togglePlatform = (p: string) => {
    setSelectedPlatforms((prev) =>
      prev.includes(p) ? prev.filter((x) => x !== p) : [...prev, p]
    );
  };

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col justify-between p-4 sm:p-8">
      {/* Top Header */}
      <div className="max-w-2xl mx-auto w-full flex items-center justify-between">
        <div className="flex items-center gap-2">
          <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-600 text-white font-bold">
            <Layers className="h-4 w-4" />
          </div>
          <span className="font-bold text-slate-900 tracking-tight">
            Omni<span className="text-blue-600">Publish</span>
          </span>
        </div>

        <button
          onClick={() => router.push('/dashboard')}
          className="text-xs font-semibold text-slate-500 hover:text-slate-800"
        >
          Skip Setup →
        </button>
      </div>

      {/* Main Container */}
      <div className="max-w-xl mx-auto w-full my-auto py-8 space-y-6">
        {/* Step Progress Tracker */}
        <div className="flex items-center justify-between">
          {steps.map((s, idx) => (
            <React.Fragment key={s.num}>
              <div className="flex items-center gap-2">
                <div
                  className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold ${
                    currentStep === s.num
                      ? 'bg-blue-600 text-white ring-4 ring-blue-100'
                      : currentStep > s.num
                      ? 'bg-emerald-500 text-white'
                      : 'bg-slate-200 text-slate-500'
                  }`}
                >
                  {currentStep > s.num ? <Check className="h-3.5 w-3.5" /> : s.num}
                </div>
              </div>
              {idx < steps.length - 1 && (
                <div
                  className={`flex-1 h-0.5 mx-2 ${
                    currentStep > idx + 1 ? 'bg-emerald-400' : 'bg-slate-200'
                  }`}
                />
              )}
            </React.Fragment>
          ))}
        </div>

        {/* Step Content */}
        <div className="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm space-y-6">
          {currentStep === 1 && (
            <div className="text-center space-y-4">
              <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 mx-auto">
                <Layers className="h-8 w-8" />
              </div>
              <h2 className="text-2xl font-bold text-slate-900">
                Upload Once. Publish Everywhere.
              </h2>
              <p className="text-xs text-slate-500 max-w-sm mx-auto leading-relaxed">
                Connect your YouTube, TikTok, and Instagram accounts once. OmniPublish automates metadata customisation, video encoding checks, and worker dispatching.
              </p>
            </div>
          )}

          {currentStep === 2 && (
            <div className="space-y-4">
              <div>
                <h2 className="text-xl font-bold text-slate-900">What is your studio or brand name?</h2>
                <p className="text-xs text-slate-500">
                  This will be your shared tenant workspace for media and credentials.
                </p>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700 mb-1">
                  Workspace Name
                </label>
                <input
                  type="text"
                  value={workspaceName}
                  onChange={(e) => setWorkspaceName(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
                  placeholder="e.g. My Media Studio"
                />
              </div>
            </div>
          )}

          {currentStep === 3 && (
            <div className="space-y-4">
              <div>
                <h2 className="text-xl font-bold text-slate-900">Which channels do you publish to?</h2>
                <p className="text-xs text-slate-500">
                  Select your primary platforms (you can connect actual OAuth accounts anytime in Settings).
                </p>
              </div>

              <div className="grid grid-cols-2 gap-3">
                {[
                  { name: 'YouTube', color: 'bg-red-600' },
                  { name: 'TikTok', color: 'bg-black' },
                  { name: 'Instagram', color: 'bg-pink-600' },
                  { name: 'Facebook', color: 'bg-blue-600' },
                ].map((p) => {
                  const checked = selectedPlatforms.includes(p.name);
                  return (
                    <div
                      key={p.name}
                      onClick={() => togglePlatform(p.name)}
                      className={`flex items-center gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
                        checked ? 'border-blue-600 bg-blue-50/40' : 'border-slate-200 hover:bg-slate-50'
                      }`}
                    >
                      <div className={`h-3 w-3 rounded-full ${p.color}`} />
                      <span className="text-xs font-semibold text-slate-800">{p.name}</span>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {currentStep === 4 && (
            <div className="text-center space-y-4">
              <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 mx-auto">
                <CheckCircle2 className="h-7 w-7" />
              </div>
              <h2 className="text-xl font-bold text-slate-900">You&apos;re All Set!</h2>
              <p className="text-xs text-slate-500 max-w-sm mx-auto">
                Your workspace <span className="font-semibold text-slate-800">{workspaceName}</span> is configured. Head over to the dashboard to upload your first video or connect accounts.
              </p>
            </div>
          )}

          <div className="flex items-center justify-between pt-4 border-t border-slate-100">
            {currentStep > 1 ? (
              <button
                type="button"
                onClick={() => setCurrentStep(currentStep - 1)}
                className="text-xs font-semibold text-slate-500 hover:text-slate-800"
              >
                Back
              </button>
            ) : (
              <div />
            )}

            <button
              type="button"
              onClick={handleNext}
              className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 transition-colors"
            >
              <span>{currentStep === 4 ? 'Launch Dashboard' : 'Continue'}</span>
              <ArrowRight className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>

      {/* Bottom Footer */}
      <div className="max-w-2xl mx-auto w-full text-center text-xs text-slate-400">
        OmniPublish Studio • Enterprise Multi-Platform Video SaaS
      </div>
    </div>
  );
}
