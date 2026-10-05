# OmniPublish — Production Reference Architecture

**Document Reference:** `docs/infrastructure/REFERENCE_ARCHITECTURE.md`  
**System Version:** OmniPublish Phase 3GA  
**Status:** Canonical Implementation Blueprint  
**Standard:** Critical Honesty Principle (Zero Handwaving, Exact Service Topologies)

---

## 1. Selected Architecture Overview

Based on the verified infrastructure requirements (`docs/infrastructure/INFRASTRUCTURE_REQUIREMENTS.md`) and factual provider research (`docs/infrastructure/PROVIDER_RESEARCH.md`), the canonical reference architecture for OmniPublish is:

```text
AWS ECS Fargate (Serverless Container Compute)
  + Amazon RDS for PostgreSQL 16 (Multi-AZ Managed Relational Database)
  + Amazon ElastiCache for Valkey / Redis 7 (Multi-AZ In-Memory Queues & Locks)
  + S3-Compatible Object Storage (Amazon S3 Standard or Cloudflare R2 Hybrid)
  + AWS Application Load Balancer (ALB) [Direct TLS 1.3 Ingress & Path Routing]
  + AWS Secrets Manager & Amazon CloudWatch
  [Phase 4 Optional Scale Enhancements: CloudFront CDN Edge & AWS RDS Proxy]
```

### Why This Architecture Fits OmniPublish
1. **Direct Alignment with Docker Targets:** OmniPublish's multi-stage Docker build defines isolated container targets (`api`, `publishing-worker`, `media-worker`, `default-worker`, `scheduler`, and `frontend`). ECS Fargate deploys these exact container images without OS virtualization overhead or VM management.
2. **Heavy FFmpeg Resource Isolation:** Media workers require isolated CPU bursts (2 vCPU), high memory (2048 MB), and 30 GB ephemeral scratch storage without starving web API traffic. Fargate tasks provide dedicated, isolated micro-VM execution per worker.
3. **Enterprise Data Reliability:** Amazon RDS PostgreSQL 16 provides synchronous Multi-AZ failover and continuous Point-In-Time Recovery (PITR) to guarantee $< 15\text{ min}$ RPO.
4. **Queue Durability & High Throughput:** ElastiCache provides sub-millisecond atomic Redis locking (`publish_lock_job_{id}`) and queue persistence with zero manual cluster administration.
5. **Least-Privilege Security:** Eliminates static cloud API credentials on worker nodes via AWS IAM Roles for Tasks.

---

## 2. End-to-End System Architecture Diagram

```mermaid
flowchart TD
    subgraph EdgeTier ["Public Edge & Ingress (MVP Baseline)"]
        UserBrowser["User Browser / Client"]
        DNS["Amazon Route 53 DNS\n(app.omnipublish.com / api.omnipublish.com)"]
        ALB["AWS Application Load Balancer\n(TLS 1.3 Termination & Path Routing)"]
        WAF["AWS WAF (Optional Prod Defense-in-Depth)"]
    end

    subgraph ComputeTier ["Private App Subnets (AWS ECS Fargate Cluster)"]
        NextJS["Next.js Frontend Container\n(Node 20 / SSR / Port 3000)"]
        LaravelAPI["Laravel 12 API Containers\n(PHP 8.3 / REST API / Port 8000)"]
        PublishWorker["Publishing Workers\n(php artisan queue:work --queue=publishing)"]
        MediaWorker["Media Processing Workers\n(FFmpeg / FFprobe --queue=media)"]
        DefaultWorker["Default Workers\n(--queue=default)"]
        Scheduler["Scheduler Daemon\n(php artisan schedule:work)"]
    end

    subgraph DataTier ["Isolated Database Subnets (Zero Public Ingress)"]
        PostgreSQL[("Amazon RDS PostgreSQL 16\nMulti-AZ Primary + Standby\n(Direct Connections: <45 max)")]
        RedisCluster[("Amazon ElastiCache Valkey/Redis\nDB 0: Queues & Locks\nDB 1: App Cache")]
    end

    subgraph StorageTier ["Object Storage & External Platforms"]
        S3Storage[("S3 / Cloudflare R2 Media Bucket\n(Presigned PUT / Encrypted Storage)")]
        YouTubeAPI["YouTube Data API v3\n(Google Cloud Resumable Upload)"]
        TikTokAPI["TikTok Content Posting API\n(Direct Post Ingestion)"]
        InstagramAPI["Meta Graph API v21.0\n(Container Ingestion via Presigned URL)"]
    end

    %% Network & Request Flows
    UserBrowser -->|HTTPS / DNS Lookup| DNS
    DNS -->|Target Alias A-Record| ALB
    WAF -.->|Protects| ALB
    ALB -->|Forward /* (Next.js SSR)| NextJS
    ALB -->|Forward /api/v1/*| LaravelAPI

    %% Browser Direct Upload Flow
    LaravelAPI -->|1. Generate Presigned PUT URL| UserBrowser
    UserBrowser -->|2. Direct Video Upload PUT| S3Storage
    UserBrowser -->|3. Confirm Upload Complete| LaravelAPI

    %% API to Data Tier
    LaravelAPI -->|Direct SQL Query| PostgreSQL
    LaravelAPI -->|Push Jobs & Locks| RedisCluster

    %% Workers Execution Flow
    RedisCluster -.->|Pop publishing jobs| PublishWorker
    RedisCluster -.->|Pop media jobs| MediaWorker
    RedisCluster -.->|Pop default jobs| DefaultWorker
    Scheduler -->|Every Minute Dispatch| RedisCluster

    %% Media Processing Flow
    MediaWorker -->|1. Download Source Video| S3Storage
    MediaWorker -->|2. FFprobe & FFmpeg Normalization| MediaWorker
    MediaWorker -->|3. Upload Derivatives & Thumbnails| S3Storage
    MediaWorker -->|4. Update Asset Status READY| RDSProxy

    %% Publishing Flow
    PublishWorker -->|1. Acquire publish_lock| RedisCluster
    PublishWorker -->|2. Query Destination Config| RDSProxy
    PublishWorker -->|3. Resumable Video Upload| YouTubeAPI
    PublishWorker -->|3. Chunked Video Upload| TikTokAPI
    PublishWorker -->|3. Dispatch Container URL| InstagramAPI
    InstagramAPI -->|Pull Video Payload| S3Storage
    PublishWorker -->|4. Persist remote_post_id| RDSProxy
```

---

## 3. Component Breakdown & Runtime Specifications

### 3.1 Next.js Frontend Service
* **Service Type:** AWS ECS Fargate Service (Multi-AZ).
* **Base Image:** `frontend/Dockerfile` (`runner` target).
* **Port:** 3000 (Internal only, routed via ALB).
* **Autoscaling Policy:** Min 1 task, Max 4 tasks (Target tracking: Average CPU $> 70\%$).
* **Environment Variables:**
  * `NEXT_PUBLIC_API_URL=https://api.omnipublish.com/api/v1`
  * `NODE_ENV=production`
  * `PORT=3000`

### 3.2 Laravel 12 API Service
* **Service Type:** AWS ECS Fargate Service (Multi-AZ).
* **Base Image:** `docker/Dockerfile` (`api` target).
* **Port:** 8000 (Internal only, routed via ALB).
* **Autoscaling Policy:** Min 2 tasks, Max 8 tasks (Target tracking: ALB Request Count $> 1,000$ per target or CPU $> 70\%$).
* **Health Probes:**
  * Liveness: `GET /health/live` (Interval: 15s, Timeout: 5s, Unhealthy threshold: 3).
  * Readiness: `GET /health/ready` (Interval: 30s, Timeout: 5s).

### 3.3 Media Processing Worker Service
* **Service Type:** AWS ECS Fargate Service.
* **Base Image:** `docker/Dockerfile` (`media-worker` target).
* **Runtime Command:** `php artisan queue:work redis --queue=media --tries=3 --timeout=600 --sleep=3 --memory=1024`.
* **Hardware Sizing:** 2.0 vCPU, 4096 MB RAM, 30 GB Ephemeral Storage.
* **Autoscaling Policy:** Min 1 task, Max 8 tasks based on CloudWatch Custom Metric (`QueueDepth_media` $> 5 \implies +2\text{ tasks}$).
* **Execution Safety:** Scratch directory purged in `finally` block; distributed lock `process_media_lock_{id}` released atomically.

### 3.4 Publishing Worker Service
* **Service Type:** AWS ECS Fargate Service.
* **Base Image:** `docker/Dockerfile` (`publishing-worker` target).
* **Runtime Command:** `php artisan queue:work redis --queue=publishing --tries=5 --timeout=300 --sleep=3 --memory=512`.
* **Hardware Sizing:** 0.5 vCPU, 1024 MB RAM, 20 GB Ephemeral Storage.
* **Autoscaling Policy:** Min 1 task, Max 8 tasks based on CloudWatch Custom Metric (`QueueDepth_publishing` $> 20 \implies +2\text{ tasks}$).
* **Network Access:** Outbound HTTPS (port 443) via NAT Gateway.

### 3.5 Default Worker Service
* **Service Type:** AWS ECS Fargate Service.
* **Base Image:** `docker/Dockerfile` (`default-worker` target).
* **Runtime Command:** `php artisan queue:work redis --queue=default --tries=3 --timeout=120 --sleep=3 --memory=256`.
* **Hardware Sizing:** 0.25 vCPU, 512 MB RAM.
* **Autoscaling Policy:** Fixed 1 task (Scales to 2 on queue depth $> 50$).

### 3.6 Scheduler Service
* **Service Type:** AWS ECS Fargate Service (Single Standalone Task).
* **Base Image:** `docker/Dockerfile` (`scheduler` target).
* **Runtime Command:** `php artisan schedule:work`.
* **Hardware Sizing:** 0.25 vCPU, 512 MB RAM.
* **High Availability & Overlap Protection:** Overlapping runs strictly prevented via Redis distributed locks (`withoutOverlapping()`). If container restarts, EventBridge or ECS restart policy immediately relaunches the task.

---

## 4. Supporting Infrastructure Services

### 4.1 Secrets Management (AWS Secrets Manager)
* Production secrets (`APP_KEY`, database credentials, Redis auth, OAuth client secrets) reside in AWS Secrets Manager.
* ECS Task Definitions reference Secrets Manager ARNs directly:
  ```json
  "secrets": [
    { "name": "APP_KEY", "valueFrom": "arn:aws:secretsmanager:us-east-1:123456789012:secret:omnipublish/prod/APP_KEY" },
    { "name": "DB_PASSWORD", "valueFrom": "arn:aws:secretsmanager:us-east-1:123456789012:secret:omnipublish/prod/DB_PASSWORD" }
  ]
  ```
* Compute tasks receive decrypted secrets as standard environment variables in memory at boot. Zero plaintext secrets exist in Git, Dockerfiles, or CI workflow logs.

### 4.2 Container Registry (Amazon ECR / GitHub Container Registry)
* All images built in `.github/workflows/docker.yml` are tagged with the immutable Git commit SHA:
  $$\text{ghcr.io/omnipublish/omnipublish-backend:}\{commit\_sha\}$$
* Container images are scanned via **Aqua Security Trivy** prior to registry admission.
* The mutable tag `latest` is strictly prohibited in deployment manifests.

### 4.3 Monitoring, Logging & Alerting
* **Log Aggregation:** Standard output from all containers streams via AWS `awslogs` driver to Amazon CloudWatch Logs with 30-day retention.
* **APM & Error Tracking:** Sentry / Datadog integration for real-time exception tracking and distributed APM tracing.
* **CloudWatch Alarms:**
  * ALB Target 5XX Error Rate $> 1\%$ for 3 consecutive evaluation periods.
  * Media queue depth $> 20$ for $> 10\text{ minutes}$.
  * Publishing queue depth $> 50$ for $> 10\text{ minutes}$.
  * PostgreSQL CPU utilization $> 80\%$ or Storage $> 85\%$.
  * Redis EngineCPUUtilization $> 75\%$.

---

## 5. Security & Isolation Boundaries

| Boundary | Mechanism | Enforced Rule |
|---|---|---|
| **Public vs. Private** | VPC Subnets | Compute tasks, RDS, and Redis reside in private subnets with no public IPv4 addresses. |
| **Ingress Control** | Security Groups | Database only accepts port 5432 from App Security Group. Redis only accepts port 6379 from App Security Group. |
| **Tenant Isolation** | PostgreSQL & App Code | Every database query is scoped by `workspace_id`. Cross-tenant IDOR requests return HTTP 404. |
| **Object Isolation** | S3 Path Partitioning | S3 paths are strictly namespaced by workspace UUID: `workspaces/{workspace_id}/media/...`. |
| **Token Protection** | AES-256-CBC Encryption | Social platform OAuth access and refresh tokens are encrypted at rest prior to database insertion. |
