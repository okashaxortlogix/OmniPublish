'use client';

import React, { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import {
  Sparkles,
  Send,
  RotateCcw,
  Copy,
  Check,
  ArrowRight,
  Layers,
  Sliders,
  CheckCircle2
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';

export default function AiAssistantPage() {
  const router = useRouter();
  const [prompt, setPrompt] = useState('How we scaled OmniPublish to 10k users with multi-platform publishing and autonomous Ralph Loops');
  const [isGenerating, setIsGenerating] = useState(false);
  const [activeTab, setActiveTab] = useState<'title' | 'description' | 'hashtags'>('title');
  const [copied, setCopied] = useState(false);

  // Generated outputs
  const [generatedTitle, setGeneratedTitle] = useState(
    'How We Scaled OmniPublish from 0 to 10k Users (Full Tech Stack Architecture)'
  );
  const [generatedDesc, setGeneratedDesc] = useState(
    'In this comprehensive breakdown, discover how OmniPublish powers independent multi-platform video publishing across YouTube, TikTok, and Instagram with worker queues and isolated fault tolerance.'
  );
  const [generatedHashtags, setGeneratedHashtags] = useState([
    '#omnipublish',
    '#saas',
    '#startups',
    '#tech',
    '#softwareengineer',
    '#buildinpublic',
    '#developer',
  ]);

  const handleGenerate = () => {
    setIsGenerating(true);
    setTimeout(() => {
      setIsGenerating(false);
      setGeneratedTitle('10k Users in 90 Days: The Engineering Architecture Behind OmniPublish');
      setGeneratedDesc('Learn how we architected a resilient multi-platform SaaS with Next.js, Laravel PHP queues, and verified platform API adapters.');
    }, 800);
  };

  const handleCopyToComposer = () => {
    if (typeof window !== 'undefined') {
      sessionStorage.setItem(
        'composer_seed',
        JSON.stringify({
          title: generatedTitle,
          description: generatedDesc,
          hashtags: generatedHashtags,
          tags: ['SaaS', 'Engineering', 'Architecture', 'Tech'],
        })
      );
    }
    router.push('/composer');
  };

  return (
    <AppShell pageTitle="AI Assistant">
      <div className="max-w-4xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="rounded bg-blue-50 px-2 py-0.5 text-xs font-bold text-blue-700">
                AI ENGINE
              </span>
              <span className="text-xs text-slate-400">Content Copywriter</span>
            </div>
            <h1 className="text-xl sm:text-2xl font-bold text-slate-900 mt-1">
              AI Content Preparation Assistant
            </h1>
            <p className="text-xs sm:text-sm text-slate-500">
              Generate high-performing titles, optimized descriptions, and platform-specific hashtags.
            </p>
          </div>

          <button
            onClick={handleCopyToComposer}
            className="flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
          >
            <span>Apply to Composer</span>
            <ArrowRight className="h-4 w-4" />
          </button>
        </div>

        {/* Input Card matching Screenshot 18 */}
        <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
          <label className="block text-xs font-semibold text-slate-700">
            Video Topic / Core Value Proposition
          </label>

          <div className="flex flex-col sm:flex-row gap-3">
            <input
              type="text"
              value={prompt}
              onChange={(e) => setPrompt(e.target.value)}
              placeholder="e.g. 5 Productivity tools every developer needs in 2026..."
              className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-blue-600 focus:outline-none"
            />
            <button
              onClick={handleGenerate}
              disabled={isGenerating}
              className="flex items-center justify-center gap-1.5 rounded-lg bg-blue-600 px-5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700 disabled:opacity-50"
            >
              <Sparkles className="h-4 w-4" />
              <span>{isGenerating ? 'Generating...' : 'Generate Copy'}</span>
            </button>
          </div>
        </div>

        {/* Output Tabs & Content Card matching Screenshot 18 */}
        <div className="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          {/* Output Selector Tabs */}
          <div className="flex border-b border-slate-200 px-4 bg-slate-50/50 gap-2">
            {[
              { id: 'title', label: 'Suggested Title' },
              { id: 'description', label: 'Suggested Description' },
              { id: 'hashtags', label: 'Viral Hashtags' },
            ].map((tab) => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id as any)}
                className={`py-3 px-3 text-xs font-semibold border-b-2 transition-colors ${
                  activeTab === tab.id
                    ? 'border-blue-600 text-blue-600'
                    : 'border-transparent text-slate-500 hover:text-slate-800'
                }`}
              >
                {tab.label}
              </button>
            ))}
          </div>

          <div className="p-6 space-y-4">
            {activeTab === 'title' && (
              <div className="space-y-3">
                <div className="flex justify-between items-center text-xs text-slate-400">
                  <span>Optimized for CTR across YouTube Shorts &amp; TikTok</span>
                  <span className="font-mono">{generatedTitle.length}/100 chars</span>
                </div>
                <textarea
                  rows={2}
                  value={generatedTitle}
                  onChange={(e) => setGeneratedTitle(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 p-3 text-sm font-semibold text-slate-900 focus:border-blue-600 focus:outline-none"
                />
              </div>
            )}

            {activeTab === 'description' && (
              <div className="space-y-3">
                <div className="flex justify-between items-center text-xs text-slate-400">
                  <span>Structured for SEO indexing &amp; call-to-actions</span>
                  <span className="font-mono">{generatedDesc.length}/2200 chars</span>
                </div>
                <textarea
                  rows={4}
                  value={generatedDesc}
                  onChange={(e) => setGeneratedDesc(e.target.value)}
                  className="w-full rounded-lg border border-slate-300 p-3 text-xs text-slate-800 focus:border-blue-600 focus:outline-none leading-relaxed"
                />
              </div>
            )}

            {activeTab === 'hashtags' && (
              <div className="space-y-3">
                <div className="flex justify-between items-center text-xs text-slate-400">
                  <span>Curated viral tags with high niche relevance</span>
                  <span>{generatedHashtags.length} tags</span>
                </div>
                <div className="flex flex-wrap gap-2 p-3 rounded-lg border border-slate-200 bg-slate-50/50">
                  {generatedHashtags.map((h) => (
                    <span
                      key={h}
                      className="rounded bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700"
                    >
                      {h}
                    </span>
                  ))}
                </div>
              </div>
            )}

            <div className="flex items-center justify-between pt-4 border-t border-slate-100">
              <button
                onClick={handleGenerate}
                className="flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-slate-900"
              >
                <RotateCcw className="h-3.5 w-3.5" />
                <span>Regenerate Variant</span>
              </button>

              <button
                onClick={handleCopyToComposer}
                className="flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
              >
                <span>Apply to Composer</span>
                <ArrowRight className="h-4 w-4" />
              </button>
            </div>
          </div>
        </div>
      </div>
    </AppShell>
  );
}
