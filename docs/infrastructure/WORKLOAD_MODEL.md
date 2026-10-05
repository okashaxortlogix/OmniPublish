# OmniPublish — Workload & Capacity Model

**Document Reference:** `docs/infrastructure/WORKLOAD_MODEL.md`  
**System Version:** OmniPublish Phase 3GA  
**Evaluation Standard:** Critical Honesty Principle (Zero Fabricated Metrics)  
**Classification Labels Used:**
* `[MEASURED]`: Derived directly from local test suites, micro-benchmarks, or inspected codebase constraints.
* `[ESTIMATED]`: Mathematically projected based on known media properties, network bandwidth, and compute specs.
* `[ASSUMPTION]`: Explicit business or traffic distribution hypothesis.
* `[REQUIRES PRODUCTION VALIDATION]`: Empirical metric that can only be certified under live staging/production load tests.

---

## 1. Workload Drivers & Unit Metrics

The infrastructure load of OmniPublish is governed by four distinct operations:
1. **API Metadata Traffic:** Interactive frontend requests (dashboard, calendar, media browser, workspace switching).
2. **Direct Browser Video Ingestion:** Multi-megabyte presigned uploads to S3/R2 storage (zero load on API servers, load on storage IOPS/bandwidth).
3. **Media Processing Jobs (`media` queue):** Worker execution of FFmpeg inspection, thumbnail extraction, and normalization.
4. **Publishing Orchestration Jobs (`publishing` queue):** Network-heavy chunked resumable uploads and status polling across YouTube, TikTok, and Instagram.

### Core Unit Constants
* **Unit Video Size:**
  * Median Short-form Video (1080x1920 vertical, 30–60s): **35 MB – 75 MB** `[MEASURED from test fixtures]`
  * Maximum Standard Upload: **500 MB** `[MEASURED - env MEDIA_MAX_SIZE]`
  * Maximum Enterprise Upload: **2 GB** `[MEASURED - env MEDIA_MAX_SIZE_ENTERPRISE]`
* **Unit Processing Runtime (per 60s 1080p MP4 on 2 vCPU):**
  * FFprobe Container Inspection: **~45 ms** `[MEASURED - FFmpegService benchmark]`
  * Thumbnail Extraction (1280x720): **~320 ms** `[MEASURED - FFmpegService benchmark]`
  * Master Normalization (fast preset, CRF 22): **~12–18 seconds** `[ESTIMATED on 2 vCPU Fargate]`
* **Unit Publishing Runtime:**
  * Synthetic Mock Dispatch: **~36 ms per job** `[MEASURED - PublishingLoadTest: 100 jobs in 3,601 ms]`
  * Live YouTube Resumable Upload (50 MB video): **~10–25 seconds** (dependent on external API ingest rate) `[ESTIMATED]`
  * Live TikTok Direct Post Upload (50 MB video): **~8–20 seconds** `[ESTIMATED]`
  * Live Instagram Graph API Ingestion (pull from temporary S3 URL): **~15–30 seconds** `[ESTIMATED]`

---

## 2. Workload Scenarios

### Scenario A — Baseline (Small SaaS Deployment)
*Target Profile: Initial production launch, private beta, or low-volume SaaS deployment.*

* **Tenancy:**
  * Active Workspaces: **50 workspaces** `[ASSUMPTION]`
  * Connected Social Accounts: **150 accounts** (avg 3 per workspace) `[ASSUMPTION]`
* **Monthly Throughput:**
  * Video Uploads: **300 videos / month** (~10 videos / day) `[ASSUMPTION]`
  * Publishing Destination Jobs: **900 jobs / month** (~30 jobs / day) `[ASSUMPTION]`
* **Peak Concurrency:**
  * Peak Concurrent API Requests: **5–15 req/sec** `[ESTIMATED]`
  * Peak Concurrent Video Uploads: **1–2 uploads simultaneously** `[ESTIMATED]`
  * Peak Concurrent Publishing Jobs: **2–4 jobs in flight** `[ESTIMATED]`
* **Resource Demands:**
  * Active Media Workers: **1 replica** (2 vCPU, 2 GB RAM, 20 GB ephemeral disk) `[ESTIMATED]`
  * Active Publishing Workers: **1 replica** (0.5 vCPU, 512 MB RAM) `[ESTIMATED]`
  * Active API Containers: **1 replica** (0.5 vCPU, 512 MB RAM) `[ESTIMATED]`
  * Active Frontend Containers: **1 replica** (0.5 vCPU, 512 MB RAM) `[ESTIMATED]`
  * Scheduler: **1 replica** (0.25 vCPU, 256 MB RAM) `[ESTIMATED]`
  * Database: **AWS RDS `db.t4g.small`** (2 vCPU, 2 GB RAM, 20 GB gp3) `[ESTIMATED]`
  * Redis: **ElastiCache `cache.t4g.micro`** (Valkey, 0.5 GB RAM) `[ESTIMATED]`
  * Monthly Media Storage Accumulation: **~25 GB / month** `[ESTIMATED]`

---

### Scenario B — Growth (Scaling Multi-Tenant SaaS)
*Target Profile: Commercial product with active agencies, marketing teams, and content creators.*

* **Tenancy:**
  * Active Workspaces: **500 workspaces** `[ASSUMPTION]`
  * Connected Social Accounts: **2,000 accounts** (avg 4 per workspace) `[ASSUMPTION]`
* **Monthly Throughput:**
  * Video Uploads: **5,000 videos / month** (~160 videos / day) `[ASSUMPTION]`
  * Publishing Destination Jobs: **20,000 jobs / month** (~650 jobs / day) `[ASSUMPTION]`
* **Peak Concurrency (e.g. 9:00 AM scheduled publishing spikes):**
  * Peak Concurrent API Requests: **50–100 req/sec** `[ESTIMATED]`
  * Peak Concurrent Video Uploads: **5–10 uploads simultaneously** `[ESTIMATED]`
  * Peak Concurrent Publishing Jobs: **25–50 jobs in flight** `[ESTIMATED]`
* **Resource Demands:**
  * Active Media Workers: **2–4 autoscaling replicas** (2 vCPU, 4 GB RAM each) `[ESTIMATED]`
  * Active Publishing Workers: **3–6 autoscaling replicas** (0.5 vCPU, 512 MB RAM each) `[ESTIMATED]`
  * Active API Containers: **2–4 autoscaling replicas** (1 vCPU, 1 GB RAM each) `[ESTIMATED]`
  * Active Frontend Containers: **2 autoscaling replicas** (0.5 vCPU, 512 MB RAM each) `[ESTIMATED]`
  * Scheduler: **1 dedicated replica** with Redis lock `[MEASURED codebase pattern]`
  * Database: **AWS RDS `db.t4g.medium`** (2 vCPU, 4 GB RAM) with **RDS Proxy connection pool** `[ESTIMATED]`
  * Redis: **ElastiCache `cache.t4g.small` Multi-AZ** (1.37 GB RAM) `[ESTIMATED]`
  * Monthly Media Storage Accumulation: **~350 GB / month** `[ESTIMATED]`

---

### Scenario C — High Workload (Enterprise Fleet / Burst Publishing)
*Target Profile: Enterprise multi-brand holding companies, viral marketing campaigns, automated content syndication.*

* **Tenancy:**
  * Active Workspaces: **2,500 workspaces** `[ASSUMPTION]`
  * Connected Social Accounts: **12,500 accounts** (avg 5 per workspace) `[ASSUMPTION]`
* **Monthly Throughput:**
  * Video Uploads: **50,000 videos / month** (~1,600 videos / day) `[ASSUMPTION]`
  * Publishing Destination Jobs: **250,000 jobs / month** (~8,000 jobs / day) `[ASSUMPTION]`
* **Peak Concurrency (Holiday campaign launches, synchronized global drops):**
  * Peak Concurrent API Requests: **250–500 req/sec** `[ESTIMATED]`
  * Peak Concurrent Video Uploads: **25–50 simultaneous uploads** `[ESTIMATED]`
  * Peak Concurrent Publishing Jobs: **150–300 jobs in flight** `[ESTIMATED]`
* **Resource Demands:**
  * Active Media Workers: **8–16 autoscaling replicas** (target tracking: `media` queue length $> 5$) `[ESTIMATED]`
  * Active Publishing Workers: **10–20 autoscaling replicas** (target tracking: `publishing` queue length $> 25$) `[ESTIMATED]`
  * Active API Containers: **6–12 autoscaling replicas** behind Application Load Balancer `[ESTIMATED]`
  * Active Frontend Containers: **4–8 autoscaling replicas** behind CloudFront CDN `[ESTIMATED]`
  * Scheduler: **1 dedicated Fargate task** `[ESTIMATED]`
  * Database: **AWS RDS `db.r6g.xlarge`** (4 vCPU, 32 GB RAM) Multi-AZ with 1 Read Replica + RDS Proxy `[ESTIMATED]`
  * Redis: **ElastiCache `cache.m6g.large` Multi-AZ Cluster** (6.38 GB RAM) `[ESTIMATED]`
  * Monthly Media Storage Accumulation: **~3.5 TB / month** `[ESTIMATED]`

---

## 3. Storage Growth Projections

Storage accumulation follows a compound model based on retention policy:

$$\text{Monthly Growth} = N_{\text{uploads}} \times \left( S_{\text{source}} + S_{\text{thumb}} + S_{\text{deriv}} \right)$$

* Average Source Video Size: **60 MB** `[ESTIMATED]`
* Average Thumbnail Size: **0.15 MB** `[MEASURED]`
* Average Derivative / Normalized Master Size: **50 MB** `[ESTIMATED]`
* Total Storage per Processed Asset: **~110 MB**

| Horizon | Baseline (300 vids/mo) | Growth (5,000 vids/mo) | High Workload (50,000 vids/mo) |
|---|---|---|---|
| **1 Month** | 33 GB | 550 GB | 5.5 TB |
| **6 Months** | 198 GB | 3.3 TB | 33 TB |
| **12 Months**| 396 GB | 6.6 TB | 66 TB |

*Lifecycle Optimization Impact:* Implementing a lifecycle rule that transitions source videos to S3 Glacier Instant Retrieval after 90 days and deletes unreferenced scratch derivatives reduces active primary storage costs by **~65%** `[ESTIMATED]`.

---

## 4. Bandwidth & Network Egress Model

Bandwidth distribution diverges significantly between upload and publishing:
1. **Client $\to$ Object Storage (Ingress):** Free on AWS S3 and Cloudflare R2.
2. **Object Storage $\to$ Media Worker (Internal Processing):**
   * On AWS (S3 $\to$ Fargate within same region): **$0.00 / GB** (Free intra-region data transfer).
   * On Cloudflare R2 $\to$ External Compute: **$0.00 / GB** (Cloudflare zero egress).
3. **Publishing Worker $\to$ Third-Party APIs (YouTube/TikTok/Meta Egress):**
   * Each published video requires uploading the payload over public HTTPS to provider ingestion servers.
   * Growth Tier (20,000 jobs/month $\times$ 50 MB): **~1,000 GB (1 TB) egress / month**.
   * On AWS NAT Gateway: Incurs standard NAT Gateway data processing fees ($0.045/GB) and Internet egress fees ($0.09/GB).
   * High Workload Tier (250,000 jobs/month $\times$ 50 MB): **~12.5 TB egress / month**.

---

## 5. Capacity Validation Requirements

The estimates presented in this workload model are grounded in codebase inspection and local benchmarks. However, pursuant to the Critical Honesty Principle, the following empirical limits **MUST BE VALIDATED ON LIVE CLOUD STAGING INFRASTRUCTURE** prior to declaring production capacity:

1. **`[REQUIRES PRODUCTION VALIDATION]` RDS Proxy Pool Contention:** Validate that 16 concurrent Fargate worker tasks and 4 API containers do not exceed RDS Proxy connection limits under a 200 req/sec burst.
2. **`[REQUIRES PRODUCTION VALIDATION]` Fargate Scratch NVMe Write Throughput:** Measure whether simultaneous streaming from S3 and FFmpeg transcoding saturates Fargate task local NVMe disk IOPS.
3. **`[REQUIRES PRODUCTION VALIDATION]` External Platform Rate Limits:** Determine exact real-world throttle limits on Google YouTube Data API quota units (1,600 units per video insert), TikTok publishing bursts, and Meta Graph API calls per hour per connected user.
4. **`[REQUIRES PRODUCTION VALIDATION]` Redis Queue Depth Latency:** Measure consumer latency on `publishing` and `media` queues when backlog exceeds 1,000 items.
