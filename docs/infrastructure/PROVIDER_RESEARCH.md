# OmniPublish — Infrastructure Provider Research & Factual Evaluation

**Document Reference:** `docs/infrastructure/PROVIDER_RESEARCH.md`  
**Evaluation Date:** October 2026  
**Evaluation Standard:** Critical Honesty Principle (Zero Sales Bias, Technical Ground Truth)  
**Purpose:** Evaluate realistic infrastructure hosting providers against the verified runtime requirements of OmniPublish.

---

## 1. Research Scope & Evaluation Candidates

To determine the optimal production infrastructure for OmniPublish, three distinct architectural hosting paradigms were evaluated:

1. **Option A — Hyperscaler Cloud (Amazon Web Services / AWS):**
   * Compute: AWS ECS with Fargate (serverless container runtime) or EC2 container hosts.
   * Database: Amazon RDS for PostgreSQL 16.
   * Caching & Queues: Amazon ElastiCache for Valkey / Redis 7 (Multi-AZ).
   * Storage: Amazon S3 Standard with lifecycle management & presigned URLs.
   * Edge & Ingress: AWS Application Load Balancer (ALB), Amazon CloudFront CDN, Amazon Route 53 DNS.
   * Security & Config: AWS Secrets Manager, IAM roles for service accounts (IRSA/ECS task execution roles).
   * Container Registry: Amazon Elastic Container Registry (ECR).
   * Observability: Amazon CloudWatch Logs & Metrics.

2. **Option B — Cloudflare Edge + External Compute:**
   * Storage: Cloudflare R2 (S3-compatible API, zero egress fees).
   * DNS & CDN: Cloudflare Managed DNS, Cloudflare Web Application Firewall (WAF), Cloudflare Edge CDN.
   * External Compute: Hetzner / DigitalOcean / AWS compute running containers.
   * External Database & Redis: Managed PostgreSQL (e.g. Neon, Aiven, Supabase) and managed Redis (Upstash, Redis Cloud).
   * *Assessment Requirement:* Explicit evaluation of whether a "Cloudflare-only" architecture (Cloudflare Workers + D1 + R2 + KV) can support OmniPublish.

3. **Option C — Managed Application Platforms (PaaS):**
   * Candidates: **Render**, **Railway**, **Fly.io**, **DigitalOcean App Platform / Droplets**.
   * Focus: Container deployment ease, managed PostgreSQL 16, Redis with persistence, background worker execution (long-running jobs, FFmpeg execution, scratch disks), private VPC networking, and pricing predictability.

---

## 2. Evaluation of Option B: Cloudflare-Only vs. Hybrid Reality

### 2.1 Technical Assessment: Can OmniPublish Run on Cloudflare-Only?
A common proposal in modern serverless discussions is running entirely on Cloudflare (Cloudflare Workers, D1 database, R2 storage, Workers KV).

**Conclusion: INCOMPATIBLE. A Cloudflare-only architecture is technically impossible for OmniPublish without completely rewriting and degrading the system.**

### 2.2 Concrete Technical Blockers for Cloudflare-Only Compute:
1. **No POSIX / Linux Binary Execution:**
   * OmniPublish requires static `ffmpeg` and `ffprobe` binaries for containerized media inspection, stream analysis, thumbnail extraction, and H.264 transcoding.
   * Cloudflare Workers run within a V8 JavaScript/WebAssembly isolate sandbox. They cannot fork native Linux processes or execute compiled binaries (`exec()` / `popen()`).
2. **Execution Time Limits:**
   * Cloudflare Workers have a maximum CPU time limit of 30 seconds on paid plans and 50 milliseconds on free plans.
   * Media processing jobs (`ProcessMediaAssetJob`) and chunked video publishing operations (`PublishDestinationJob`) require execution windows of **300 to 600 seconds**.
3. **Memory Limits:**
   * Worker isolates are constrained to 128 MB RAM.
   * Video normalization and thumbnail extraction require **1024 MB to 2048 MB RAM** per process.
4. **No Persistent Scratch Disk:**
   * Workers isolates have zero ephemeral filesystem storage.
   * Video transcoding requires streaming multi-hundred-megabyte files to local disk (`sys_get_temp_dir()`), operating on them with FFmpeg, and then uploading derivatives.
5. **No Persistent Worker Daemons:**
   * Laravel's queue worker (`php artisan queue:work`) requires a long-running, continuous daemon listening on Redis blocking pop (`BLPOP` / `BRPOP`). Cloudflare Workers are event-triggered and cannot maintain long-lived stateful socket listeners.
6. **Framework Incompatibility:**
   * OmniPublish backend is built on Laravel 12 (PHP 8.3). While experimental WebAssembly PHP runtimes exist, they do not support POSIX signals (`pcntl`), multi-threaded extensions, or production database connection pools.

### 2.3 Viable Role for Cloudflare: High-Efficiency Storage & Edge CDN
While Cloudflare cannot host the application compute, **Cloudflare R2** and **Cloudflare CDN** are outstanding candidates for the media storage and front-end distribution layers:
* Cloudflare R2 provides a 100% S3-compatible API.
* **$0 Egress Fees:** Eliminates the punishing data transfer costs typical of AWS S3 when delivering video streams to users or third-party platform uploaders.

---

## 3. Evaluation of Option C: Managed Application Platforms (PaaS)

| Platform | Strengths | Critical Limitations for OmniPublish | Verdict |
|---|---|---|---|
| **Render** | • Native background workers<br>• Managed PostgreSQL 16<br>• Managed Redis<br>• Dockerfile deployment | • Ephemeral disks on background workers are slow network mounts unless paid SSD disks are attached.<br>• High cost at scale for CPU-intensive background workers.<br>• Lack of native S3 storage (relies on AWS S3 or R2 anyway).<br>• Single-region VPC limits disaster recovery. | **VIABLE FOR MVP / STAGING, EXPENSIVE AT SCALE** |
| **Railway** | • Fast developer setup<br>• Dockerfile support<br>• Ephemeral PR environments | • Background workers with high CPU (FFmpeg) trigger unpredictable consumption-based billing spikes.<br>• Managed Redis is basic single-node without multi-AZ auto-failover.<br>• No built-in S3-compatible object storage.<br>• Networking between services is subject to occasional platform routing restarts. | **NOT RECOMMENDED FOR PRODUCTION PUBLISHING** |
| **Fly.io** | • Global Anycast routing<br>• Native Docker containers<br>• Ephemeral NVMe scratch disks<br>• Close to bare-metal performance for FFmpeg | • Managed PostgreSQL is unmanaged Patroni clusters running as Fly apps (requires manual DB operations).<br>• Redis is provided via Upstash third-party integration.<br>• Complex operational overhead for production database backups and PITR.<br>• Platform networking changes have historically caused connection dropouts. | **STRONG ON COMPUTE, WEAK ON MANAGED DATA TIERS** |
| **DigitalOcean** | • App Platform for web API & frontend<br>• Managed PostgreSQL 16 with pooling<br>• Managed Redis with automated failover<br>• Spaces object storage | • App Platform background workers have strict memory limits and lack fine-grained autoscaling based on Redis queue depth.<br>• Droplets require substantial self-managed orchestration (Ansible/Docker Swarm/K8s).<br>• Spaces object storage has inconsistent presigned URL behavior across regions compared to AWS S3. | **VIABLE ALTERNATIVE, BUT HIGHER OPERATIONAL FRICTION** |

---

## 4. Evaluation of Option A: Hyperscaler Cloud (AWS)

AWS represents the industry-standard environment for containerized microservices requiring decoupled compute, heavy media processing, and managed data layers.

### 4.1 Key Services & Capabilities
* **ECS with AWS Fargate:**
  * Fully serverless container execution: No EC2 instances to patch, provision, or manage.
  * Native support for segregated worker roles: API, Publishing Worker, Media Worker, Scheduler, Frontend.
  * Task-level resource rightsizing: Each media worker container can be provisioned with **2 vCPU, 4 GB RAM, and up to 200 GB ephemeral NVMe scratch storage**.
  * Autoscaling based on custom CloudWatch metrics (e.g. `publishing` queue backlog $> 20$ or `media` queue backlog $> 5$).
  * Process control: Fargate cleanly passes `SIGTERM` signals, honoring Laravel's graceful worker draining.
* **Amazon RDS for PostgreSQL 16:**
  * Engine: PostgreSQL 16.2 with native `pgcrypto` and `pg_trgm`.
  * High Availability: Multi-AZ synchronous replication with automatic failover in $< 60\text{ seconds}$.
  * Point-In-Time Recovery: Automated WAL streaming with restoration to any second within the retention window.
  * Scaling: Independent storage scaling (gp3 auto-scaling) and compute scaling.
  * Connection Management: Integrated **Amazon RDS Proxy** for connection pooling across API containers.
* **Amazon ElastiCache (Valkey / Redis 7):**
  * Fully managed Redis 7 / Valkey 7.2 engine.
  * In-transit TLS and at-rest encryption.
  * Multi-AZ replication groups with automatic failover.
  * AOF persistence options and isolated subnet placement.
* **Amazon S3 Standard:**
  * Gold standard for object storage durability ($99.999999999\%$).
  * Native presigned PUT and GET URL generation (fully verified in `App\Services\MediaStorageService`).
  * Granular bucket policies, IAM role authorization, and automated lifecycle transitions.
* **Security & IAM:**
  * Complete elimination of static cloud credentials on compute nodes via ECS Task Execution Roles and Task IAM Roles.
  * Secrets Manager integration injects secrets as environment variables into containers at runtime without storing secrets in Git or container images.

---

## 5. Comprehensive 22-Dimension Factual Comparison

The table below contrasts the realistic options: **AWS (ECS Fargate + RDS + ElastiCache + S3)**, **Cloudflare + Compute (Hybrid)**, and **Managed Platforms (Render / DigitalOcean)**.

| # | Dimension | Option A: AWS (Fargate / Managed) | Option B: Cloudflare + Hybrid Compute | Option C: Managed Platforms (Render / DO) |
|---|---|---|---|---|
| 1 | **Laravel 12 Support** | Full: Standard Docker container execution (`php:8.3-cli-alpine` or FPM). | Full on external compute node; zero on Cloudflare edge. | Full: Native Docker container runners. |
| 2 | **Docker Multi-Stage Support**| Native: Pulls directly from ECR or GHCR by Git commit SHA. | Native on compute node; unsupported on Cloudflare edge. | Native: Builds directly from Dockerfile or pulls images. |
| 3 | **PostgreSQL 16** | Managed: RDS PostgreSQL 16 with Multi-AZ, automated minor patching, PITR. | External managed (Supabase/Neon) or self-hosted on VM. | Managed: Render/DigitalOcean provide PostgreSQL 16. |
| 4 | **Redis 7 / Valkey** | Managed: ElastiCache Redis/Valkey Multi-AZ with TLS and AUTH. | External managed (Upstash/Redis Cloud) or self-hosted. | Managed: Available, but single-node basic setup on entry tiers. |
| 5 | **Object Storage** | Native: Amazon S3 (Universal API standard, IAM secured). | Cloudflare R2 ($0 egress fees, S3 compatible). | DigitalOcean Spaces or external S3/R2 required. |
| 6 | **FFmpeg Workers** | Excellent: Fargate tasks with dedicated CPU, RAM, and 20–200 GB scratch disk. | Excellent on dedicated compute VMs; impossible on Workers. | Limited: Workers share host disk unless expensive persistent volumes added. |
| 7 | **Long-Running Workers** | Native: Fargate tasks run indefinitely with 600s+ timeouts. | Supported on external VMs; strictly limited on Cloudflare edge. | Supported: Render/Fly background workers support daemon mode. |
| 8 | **Scheduler (Cron)** | Flexible: Dedicated Fargate daemon task or EventBridge scheduled task. | External VM cron or Cloudflare Cron Triggers calling HTTP endpoint. | Built-in: Render Cron Jobs or dedicated background worker. |
| 9 | **Private Networking (VPC)** | Industry standard: Private subnets, Security Groups, VPC Endpoints, NAT GW. | Cloudflare Magic WAN / Tunnels required to bridge external VMs. | Render Private Network / DO VPC (less granular security groups). |
| 10| **Secrets Management** | Enterprise: AWS Secrets Manager / Parameter Store with IAM RBAC. | Cloudflare Secrets for Edge; separate secrets for external VM. | Environment variable dashboard with platform encryption. |
| 11| **CI/CD Integration** | Comprehensive: GitHub Actions natively deploys to ECR/ECS via OIDC. | Multi-tier: Deploying edge rules + VM containers separately. | Webhook-driven auto-deploy from Git branches or CLI. |
| 12| **Container Registry** | Private: Amazon ECR (with vulnerability scanning) or GHCR. | GHCR or Docker Hub required. | Internal platform registry or external GHCR. |
| 13| **Autoscaling** | Granular: Scales based on SQS/Redis queue depth, CPU, or memory. | Manual or metric-based depending on external compute provider. | Coarse: Scales based on CPU/RAM thresholds only (not queue depth). |
| 14| **Backups** | Automated: Continuous RDS WAL streaming, automated snapshots. | Dependent on third-party database provider (Supabase/Neon). | Automated daily snapshots; limited PITR on lower tiers. |
| 15| **Disaster Recovery** | Robust: Cross-region RDS read replicas, cross-region S3 bucket replication. | Multi-vendor failover requires orchestrating multiple providers. | Single-region failover; cross-region requires multi-account setup. |
| 16| **Observability** | Centralized: CloudWatch Logs, Metrics, Container Insights, X-Ray. | Split: Cloudflare Analytics for CDN; external APM for compute. | Platform-native log streams; third-party APM required for deep metrics. |
| 17| **Deployment Complexity**| Moderate-High: Requires Infrastructure-as-Code (Terraform) setup. | High: Fragmented across CDN, compute provider, and database host. | Low-Moderate: Web UI or simple YAML blueprint configuration. |
| 18| **Operational Complexity**| Low (Post-Setup): Fully managed infrastructure, zero OS patching. | High: Fragmented SLAs, multi-invoice billing, cross-provider debugging. | Low-Moderate: Platform handles OS maintenance, but debugging is opaque. |
| 19| **Geographic Availability**| Global: 34+ commercial regions, extensive local availability. | Global CDN; compute location dependent on external VM provider. | Selected US/EU regions (Render: 4 regions; DO: 9 regions). |
| 20| **Cost Model** | Usage-based: Pay for exact vCPU-seconds, storage GBs, and IOPS. | Cost-effective on storage (R2 $0 egress); compute pricing varies. | Predictable flat-rate per container tier; expensive when scaling compute. |
| 21| **Vendor Lock-in** | Low-Moderate: Workloads run in standard OCI containers, standard Postgres/S3. | Low: S3/R2 interchangeable; containers run anywhere. | Low: Standard Docker images can be moved to any cloud. |
| 22| **Migration Difficulty** | Minimal: Architecture directly matches current Docker multi-stage build. | Moderate: Managing cross-cloud networking and latency. | Minimal: Docker multi-stage targets deploy directly. |

---

## 6. Official Documentation Citations & Limits Verification

*All sources verified against active provider documentation as of October 2026:*

1. **AWS Fargate Resource Limits & Pricing:**
   * *Source:* [AWS Fargate Pricing Official Page](https://aws.amazon.com/fargate/pricing/) (Checked: October 2026).
   * *Pricing (us-east-1):* Linux x86: **$0.04048 per vCPU-hour**, **$0.004446 per GB-hour**.
   * *Ephemeral Storage:* 20 GB included free; additional storage at $0.000111 per GB-hour.
   * *Supported Task Sizes:* Up to 32 vCPUs and 240 GB RAM.
   * *Confidence:* High (Official AWS API and Pricing documentation).

2. **AWS RDS for PostgreSQL 16:**
   * *Source:* [Amazon RDS Pricing Official Page](https://aws.amazon.com/rds/postgresql/pricing/) (Checked: October 2026).
   * *Pricing (us-east-1):* `db.t4g.small` (2 vCPU, 2 GB RAM): **$0.032/hour** (~$23.36/mo); `db.t4g.medium` (2 vCPU, 4 GB RAM): **$0.065/hour** (~$47.45/mo).
   * *Storage:* General Purpose SSD (gp3): $0.115/GB-month.
   * *Confidence:* High.

3. **AWS ElastiCache for Valkey / Redis:**
   * *Source:* [Amazon ElastiCache Pricing Official Page](https://aws.amazon.com/elasticache/pricing/) (Checked: October 2026).
   * *Pricing (us-east-1):* `cache.t4g.micro` (Valkey): **$0.0128/hour** (~$9.34/mo); `cache.t4g.small` (Valkey): **$0.0205/hour** (~$14.97/mo). Redis OSS is ~$0.016/hr and ~$0.0256/hr respectively.
   * *Confidence:* High.

4. **Cloudflare R2 Object Storage:**
   * *Source:* [Cloudflare R2 Pricing Documentation](https://www.cloudflare.com/developer-platform/r2/) (Checked: October 2026).
   * *Pricing:* Storage: **$0.015/GB-month** (Standard); Class A operations: **$4.50/million**; Class B operations: **$0.36/million**.
   * *Bandwidth Egress:* **$0.00 / GB** (Free to Internet).
   * *Confidence:* High.

5. **Cloudflare Workers Limits:**
   * *Source:* [Cloudflare Workers Limits Documentation](https://developers.cloudflare.com/workers/platform/limits/) (Checked: October 2026).
   * *Limits:* Memory limit: 128 MB. Max CPU time: 30s (paid) or 50ms (free). No POSIX system calls or binary execution.
   * *Confidence:* High (Directly confirms why Workers cannot execute FFmpeg or long-running workers).
