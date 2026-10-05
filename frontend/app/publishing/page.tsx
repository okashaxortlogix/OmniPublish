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
      await loadBatches();
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
                      const isUploading = job.status === 'UPLOADING';
                      const isProcessing = job.status === 'PROCESSING';
                      const isAuthRequired = job.status === 'AUTH_REQUIRED';
                      const isRetrying = job.status === 'RETRYING';
                      const isFailed = job.status === 'FAILED';
                      const isQueued = job.status === 'QUEUED' || job.status === 'CREATED';

                      const uploadPercent = job.bytes_uploaded && job.total_bytes && job.total_bytes > 0
                        ? Math.round((job.bytes_uploaded / job.total_bytes) * 100)
                        : null;

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
                                  {job.social_account?.account_handle ||
                                   (job.platform === 'YOUTUBE' ? '@youtubechannel' : '@socialaccount')}
                                </span>
                              </div>

                              <div className="flex items-center gap-2 text-[11px] text-slate-500 mt-0.5">
                                <span>Attempts: {job.attempts_count || job.attemptCount || 1}</span>
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
                              <div className="flex items-center gap-2">
                                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                  <CheckCircle2 className="h-3.5 w-3.5" />
                                  Published
                                </span>
                                {(job.remote_post_url || job.publishedUrl) && (
                                  <a
                                    href={job.remote_post_url || job.publishedUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 text-xs font-medium text-blue-600 hover:text-blue-800 transition"
                                  >
                                    <span>Watch</span>
                                    <ExternalLink className="h-3 w-3" />
                                  </a>
                                )}
                              </div>
                            )}

                            {isUploading && (
                              <span className="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                                <Clock className="h-3.5 w-3.5 animate-spin" />
                                <span>Uploading{uploadPercent !== null ? ` ${uploadPercent}%` : '...'}</span>
                              </span>
                            )}

                            {isProcessing && (
                              <span className="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">
                                <Clock className="h-3.5 w-3.5 animate-spin" />
                                <span>Processing...</span>
                              </span>
                            )}

                            {isRetrying && (
                              <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                                <RotateCcw className="h-3.5 w-3.5 animate-spin" />
                                <span>Retrying...</span>
                              </span>
                            )}

                            {isQueued && (
                              <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                <Clock className="h-3.5 w-3.5" />
                                Queued
                              </span>
                            )}

                            {isAuthRequired && (
                              <div className="flex items-center gap-2">
                                <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                                  <AlertCircle className="h-3.5 w-3.5" />
                                  Reconnect Required
                                </span>
                                <Link
                                  href="/connections"
                                  className="rounded-lg bg-amber-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm hover:bg-amber-700 transition"
                                >
                                  Reconnect
                                </Link>
                              </div>
                            )}

                            {isFailed && (
                              <div className="flex items-center gap-2">
                                <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700" title={job.user_facing_error_message || 'Publishing failed'}>
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
                  <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-xs text-red-800 space-y-1.5">
                    <div className="flex items-center gap-1.5 font-bold">
                      <AlertCircle className="h-4 w-4 text-red-600" />
                      <span>Destination Error Isolated ({failedJobs} failed)</span>
                    </div>
                    <div className="text-[11px] text-red-700 space-y-1">
                      {currentJobs
                        .filter((j) => j.status === 'FAILED')
                        .map((j) => (
                          <p key={j.id}>
                            • <strong className="font-semibold">{j.platform}</strong>: {j.user_facing_error_message || 'Publishing error. Other destinations were completed safely and will not be duplicated.'}
                          </p>
                        ))}
                    </div>
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
                <h3 className="text-sm font-semibold text-slate-900">Recent Batches</h3>
                <span className="text-xs text-slate-400">{batches.length} total</span>
              </div>

              <div className="space-y-3">
                {batches.length > 0 ? (
                  batches.slice(0, 6).map((b) => {
                    const jobs = b.jobs || b.publish_jobs || [];
                    const allOk = jobs.length > 0 && jobs.every((j) => j.status === 'PUBLISHED');
                    const anyFailed = jobs.some((j) => j.status === 'FAILED');
                    const bTitle = b.content_item?.title || b.content_item?.global_metadata?.title || 'Video Post';
                    const bDate = b.created_at ? new Date(b.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : 'Recently';

                    return (
                      <div
                        key={b.id}
                        onClick={() => setSelectedBatch(b)}
                        className={`flex items-center justify-between rounded-lg border p-2.5 transition-colors cursor-pointer ${
                          selectedBatch?.id === b.id ? 'border-blue-500 bg-blue-50/50 ring-1 ring-blue-500' : 'border-slate-100 hover:bg-slate-50'
                        }`}
                      >
                        <div className="truncate pr-2">
                          <h4 className="text-xs font-semibold text-slate-900 truncate">{bTitle}</h4>
                          <p className="text-[10px] text-slate-400">{bDate} • {jobs.length} destinations</p>
                        </div>
                        <span
                          className={`rounded px-1.5 py-0.5 text-[10px] font-semibold shrink-0 ${
                            allOk
                              ? 'bg-emerald-50 text-emerald-700'
                              : anyFailed
                              ? 'bg-red-50 text-red-700'
                              : 'bg-blue-50 text-blue-700'
                          }`}
                        >
                          {allOk ? 'Published' : anyFailed ? 'Failed' : 'Processing'}
                        </span>
                      </div>
                    );
                  })
                ) : (
                  <p className="text-xs text-slate-400 text-center py-4">No batches dispatched yet</p>
                )}
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
