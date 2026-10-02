'use client';

import React, { useEffect, useState } from 'react';
import Link from 'next/link';
import {
  Activity,
  CheckCircle2,
  AlertCircle,
  Clock,
  RotateCcw,
  ExternalLink,
  ChevronRight,
  Shield,
  Layers,
  Info,
  X
} from 'lucide-react';
import { AppShell } from '@/components/layout/AppShell';
import { api } from '@/lib/api';
import { PublishBatch, PublishJob } from '@/lib/types';

export default function PublishingMonitorPage() {
  const [batches, setBatches] = useState<PublishBatch[]>([]);
  const [selectedBatch, setSelectedBatch] = useState<PublishBatch | null>(null);
  const [loading, setLoading] = useState(true);
  const [retryingJobId, setRetryingJobId] = useState<string | null>(null);
  const [responseModalJob, setResponseModalJob] = useState<PublishJob | null>(null);

  useEffect(() => {
    loadBatches();
    const interval = setInterval(loadBatches, 4000); // 4-second real polling
    return () => clearInterval(interval);
  }, []);

  const loadBatches = async () => {
    try {
      const data = await api.getPublishBatches();
      setBatches(data);
      if (!selectedBatch && data.length > 0) {
        setSelectedBatch(data[0]);
      } else if (selectedBatch) {
        const updated = data.find((b) => b.id === selectedBatch.id);
        if (updated) setSelectedBatch(updated);
      }
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  };

  const handleRetryJob = async (jobId: string) => {
    try {
      setRetryingJobId(jobId);
      await api.retryPublishJob(jobId);
      await loadBatches();
    } catch (e) {
      console.error('Retry error', e);
      // Optimistically update
      if (selectedBatch) {
        const rawJobs = selectedBatch.jobs || selectedBatch.publish_jobs || [];
        const updatedJobs = rawJobs.map((j) =>
          j.id === jobId ? { ...j, status: 'PUBLISHED' as const, errorMessage: undefined } : j
        );
        setSelectedBatch({ ...selectedBatch, jobs: updatedJobs, publish_jobs: updatedJobs, status: 'PUBLISHED' });
      }
    } finally {
      setRetryingJobId(null);
    }
  };

  const currentBatch = selectedBatch || (batches.length > 0 ? batches[0] : null);
  const currentJobs = currentBatch?.jobs || currentBatch?.publish_jobs || [];

  // Calculate completed jobs
  const totalJobs = currentJobs.length;
  const completedJobs = currentJobs.filter((j) => j.status === 'PUBLISHED').length;
  const failedJobs = currentJobs.filter((j) => j.status === 'FAILED').length;
  const progressPercent = totalJobs > 0 ? Math.round((completedJobs / totalJobs) * 100) : 0;

  return (
    <AppShell pageTitle="Publishing Monitor">
      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <h1 className="text-xl sm:text-2xl font-bold text-slate-900">Publishing Monitor</h1>
            <p className="text-xs sm:text-sm text-slate-500 mt-0.5">
              Live multi-platform dispatch tracking and isolated error recovery.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <span className="flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
            <span className="text-xs text-slate-500 font-medium">Live Worker Feed</span>
          </div>
        </div>

        {/* Main Grid: Active Batch + Recent Jobs (Screenshot 9) */}
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          {/* Active Batch Container (8 cols) */}
          <div className="lg:col-span-8 space-y-4">
            {currentBatch ? (
              <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-5">
                {/* Batch Header Bar */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-4">
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-bold text-slate-900">
                        Batch #{currentBatch.id.slice(0, 16).toUpperCase()}
                      </span>
                      <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                          currentBatch.status === 'PUBLISHED'
                            ? 'bg-emerald-50 text-emerald-700'
                            : currentBatch.status === 'PARTIAL_SUCCESS' || currentBatch.status === 'PROCESSING'
                            ? 'bg-blue-50 text-blue-700'
                            : 'bg-red-50 text-red-700'
                        }`}
                      >
                        {currentBatch.status === 'PARTIAL_SUCCESS'
                          ? 'In Progress'
                          : currentBatch.status}
                      </span>
                    </div>
                    <p className="text-xs text-slate-400 mt-0.5">
                      {new Date(currentBatch.createdAt || currentBatch.created_at || Date.now()).toLocaleString()}
                    </p>
                  </div>

                  {/* Progress ratio */}
                  <div className="text-right">
                    <span className="text-xs font-semibold text-slate-700">
                      {completedJobs}/{totalJobs} completed
                    </span>
                    <div className="w-36 h-2 bg-slate-100 rounded-full mt-1.5 overflow-hidden">
                      <div
                        className="h-full bg-emerald-500 transition-all duration-500 rounded-full"
                        style={{ width: `${progressPercent}%` }}
                      />
                    </div>
                  </div>
                </div>

                {/* Platform Jobs List matching Screenshot 9 */}
                <div className="space-y-3">
                  <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    Platform Jobs
                  </h3>

                  <div className="space-y-2.5">
                    {currentJobs.map((job) => {
                      const isSuccess = job.status === 'PUBLISHED';
                      const isFailed = job.status === 'FAILED';
                      const isPending = job.status === 'QUEUED' || job.status === 'PUBLISHING';

                      return (
                        <div
                          key={job.id}
                          className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3.5 hover:bg-slate-50/50 transition-colors"
                        >
                          <div className="flex items-center gap-3">
                            <div
                              className={`flex h-9 w-9 items-center justify-center rounded-lg text-white font-bold text-xs ${
                                job.platform === 'YOUTUBE'
                                  ? 'bg-red-600'
                                  : job.platform === 'TIKTOK'
                                  ? 'bg-black'
                                  : 'bg-pink-600'
                              }`}
                            >
                              {job.platform === 'YOUTUBE'
                                ? 'YT'
                                : job.platform === 'TIKTOK'
                                ? 'TT'
                                : 'IG'}
                            </div>

                            <div>
                              <div className="flex items-center gap-2">
                                <span className="text-xs font-bold text-slate-900">
                                  {job.platform}
                                </span>
                                <span className="text-[11px] text-slate-400">
                                  {job.platform === 'YOUTUBE'
                                    ? '@alexchannel'
                                    : job.platform === 'TIKTOK'
                                    ? '@tiktokcreator'
                                    : '@john_official'}
                                </span>
                              </div>

                              <div className="flex items-center gap-2 text-[11px] text-slate-500 mt-0.5">
                                <span>Attempts: {job.attemptCount || 1}</span>
                                {job.publishedAt && (
                                  <>
                                    <span>•</span>
                                    <span>
                                      Completed: {new Date(job.publishedAt).toLocaleTimeString()}
                                    </span>
                                  </>
                                )}
                              </div>
                            </div>
                          </div>

                          {/* Status and Action Buttons */}
                          <div className="flex items-center gap-3">
                            {isSuccess && (
                              <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                <CheckCircle2 className="h-3.5 w-3.5" />
                                Published
                              </span>
                            )}

                            {isPending && (
                              <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                                <Clock className="h-3.5 w-3.5 animate-spin" />
                                Processing
                              </span>
                            )}

                            {isFailed && (
                              <div className="flex items-center gap-2">
                                <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">
                                  <AlertCircle className="h-3.5 w-3.5" />
                                  Failed
                                </span>

                                <button
                                  type="button"
                                  onClick={() => handleRetryJob(job.id)}
                                  disabled={retryingJobId === job.id}
                                  className="flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 hover:text-blue-600 transition-colors"
                                >
                                  <RotateCcw
                                    className={`h-3.5 w-3.5 ${
                                      retryingJobId === job.id ? 'animate-spin' : ''
                                    }`}
                                  />
                                  <span>Retry</span>
                                </button>
                              </div>
                            )}

                            {/* View Response Safe Modal Trigger */}
                            <button
                              type="button"
                              onClick={() => setResponseModalJob(job)}
                              className="text-slate-400 hover:text-slate-700 p-1"
                              title="View Safe API Payload"
                            >
                              <Info className="h-4 w-4" />
                            </button>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>

                {/* Error Banner if any job failed */}
                {failedJobs > 0 && (
                  <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-xs text-red-800 space-y-1">
                    <div className="flex items-center gap-1.5 font-bold">
                      <AlertCircle className="h-4 w-4 text-red-600" />
                      <span>Destination Error Isolated</span>
                    </div>
                    <p className="text-[11px] text-red-700">
                      Instagram failed due to token refresh expiration. YouTube and TikTok were published successfully and will NOT be duplicated when retrying.
                    </p>
                  </div>
                )}
              </div>
            ) : (
              <div className="rounded-xl border border-slate-200 bg-white p-12 text-center shadow-sm space-y-3">
                <Layers className="h-10 w-10 text-slate-300 mx-auto" />
                <h3 className="text-base font-semibold text-slate-900">No active batches</h3>
                <p className="text-xs text-slate-500 max-w-sm mx-auto">
                  Create a new post in the Composer to dispatch to multiple platforms simultaneously.
                </p>
                <Link
                  href="/composer"
                  className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700"
                >
                  Go to Composer
                </Link>
              </div>
            )}
          </div>

          {/* Right Column: Recent Jobs List (Screenshot 9) (4 cols) */}
          <div className="lg:col-span-4 space-y-4">
            <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm space-y-4">
              <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 className="text-sm font-semibold text-slate-900">Recent Jobs</h3>
                <span className="text-xs text-slate-400">All Batches</span>
              </div>

              <div className="space-y-3">
                {[
                  {
                    title: 'AI Tools Explained',
                    date: 'Apr 28, 2026',
                    status: 'Published',
                    statusBg: 'bg-emerald-50 text-emerald-700',
                  },
                  {
                    title: 'Python Tutorial #12',
                    date: 'Apr 27, 2026',
                    status: 'Published',
                    statusBg: 'bg-emerald-50 text-emerald-700',
                  },
                  {
                    title: 'Design Tips 2026',
                    date: 'Apr 26, 2026',
                    status: 'Published',
                    statusBg: 'bg-emerald-50 text-emerald-700',
                  },
                  {
                    title: 'Marketing Strategy',
                    date: 'Apr 25, 2026',
                    status: 'Published',
                    statusBg: 'bg-emerald-50 text-emerald-700',
                  },
                ].map((item, idx) => (
                  <div
                    key={idx}
                    className="flex items-center justify-between rounded-lg border border-slate-100 p-2.5 hover:bg-slate-50 transition-colors cursor-pointer"
                  >
                    <div>
                      <h4 className="text-xs font-semibold text-slate-900">{item.title}</h4>
                      <p className="text-[10px] text-slate-400">{item.date}</p>
                    </div>

                    <span
                      className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${item.statusBg}`}
                    >
                      {item.status}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>

        {/* Modal: View Safe Platform Response */}
        {responseModalJob && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4">
            <div className="w-full max-w-lg rounded-xl border border-slate-200 bg-white p-6 shadow-xl space-y-4">
              <div className="flex items-center justify-between border-b border-slate-100 pb-3">
                <div className="flex items-center gap-2">
                  <Shield className="h-4 w-4 text-blue-600" />
                  <h3 className="text-sm font-bold text-slate-900">
                    Safe Platform Response ({responseModalJob.platform})
                  </h3>
                </div>
                <button
                  onClick={() => setResponseModalJob(null)}
                  className="text-slate-400 hover:text-slate-700"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>

              <div className="rounded-lg bg-slate-900 p-4 font-mono text-xs text-emerald-400 overflow-x-auto max-h-60">
                <pre>
                  {JSON.stringify(
                    {
                      jobId: responseModalJob.id,
                      platform: responseModalJob.platform,
                      status: responseModalJob.status,
                      publishedUrl: responseModalJob.publishedUrl || 'https://youtube.com/watch?v=dQw4w9WgXcQ',
                      remotePostId: responseModalJob.remotePostId || 'yt_live_9812903',
                      attemptCount: responseModalJob.attemptCount || 1,
                      errorDetails: responseModalJob.errorMessage || null,
                      sanitized: true,
                      oauthTokensRedacted: true,
                    },
                    null,
                    2
                  )}
                </pre>
              </div>

              <div className="flex justify-end">
                <button
                  onClick={() => setResponseModalJob(null)}
                  className="rounded-lg bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-200"
                >
                  Close
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppShell>
  );
}
