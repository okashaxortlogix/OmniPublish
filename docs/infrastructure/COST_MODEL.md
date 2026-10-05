# OmniPublish — Infrastructure Cost Model & Financial Projections

**Document Reference:** `docs/infrastructure/COST_MODEL.md`  
**Evaluation Date:** October 2026  
**Pricing Region:** AWS US East (N. Virginia, `us-east-1`)  
**Pricing Standards:** Official Current Provider Price Books  
**Classification Labels Used:**
* `[QUOTED PRICE]`: Directly derived from active official provider pricing documentation as of October 2026.
* `[ESTIMATE]`: Projected composite cost based on workload modeling calculations.
* `[USAGE-DEPENDENT]`: Cost governed strictly by dynamic traffic, storage accumulation, or egress volume.
* `[REGION-DEPENDENT]`: Varies based on chosen cloud data center.

---

## 1. Unit Rate Summary (Official 2026 Pricing)

| Service / Resource | Unit Rate (us-east-1) | Pricing Source & Verification |
|---|---|---|
| **AWS Fargate vCPU** | $0.04048 per vCPU-hour `[QUOTED PRICE]` | AWS Fargate Pricing (Oct 2026) |
| **AWS Fargate Memory** | $0.004446 per GB-hour `[QUOTED PRICE]` | AWS Fargate Pricing (Oct 2026) |
| **AWS Fargate Storage** | 20 GB free; $0.000111/GB-hour thereafter `[QUOTED PRICE]` | AWS Fargate Pricing (Oct 2026) |
| **RDS PostgreSQL (`db.t4g.small`)** | $0.032 per hour (~$23.36/month) `[QUOTED PRICE]` | Amazon RDS Pricing (Single-AZ) |
| **RDS PostgreSQL (`db.t4g.medium`)**| $0.065 per hour (~$47.45/month) `[QUOTED PRICE]` | Amazon RDS Pricing (Single-AZ; Multi-AZ is $0.130/hr) |
| **RDS PostgreSQL Storage (gp3)** | $0.115 per GB-month `[QUOTED PRICE]` | Amazon RDS gp3 Storage |
| **ElastiCache Valkey (`cache.t4g.micro`)** | $0.0128 per hour (~$9.34/month) `[QUOTED PRICE]` | Amazon ElastiCache Pricing (Single Node) |
| **ElastiCache Valkey (`cache.t4g.small`)** | $0.0205 per hour (~$14.97/month) `[QUOTED PRICE]` | Amazon ElastiCache Pricing (Multi-AZ is ~$29.94/mo) |
| **Amazon S3 Standard Storage** | $0.023 per GB-month (First 50 TB) `[QUOTED PRICE]` | Amazon S3 Pricing |
| **Cloudflare R2 Storage** | $0.015 per GB-month (10 GB free) `[QUOTED PRICE]` | Cloudflare R2 Pricing |
| **Cloudflare R2 Egress** | **$0.00 per GB (FREE)** `[QUOTED PRICE]` | Cloudflare R2 Pricing |
| **AWS Internet Egress (via NAT/IGW)** | $0.09 per GB (First 10 TB) `[QUOTED PRICE]` | AWS EC2 Data Transfer |
| **AWS Application Load Balancer** | $0.0225 per hour (~$16.43/mo) + $0.008/LCU-hr `[QUOTED PRICE]` | AWS Elastic Load Balancing |
| **AWS NAT Gateway** | $0.045 per hour (~$32.85/mo) + $0.045/GB processed `[QUOTED PRICE]` | Amazon VPC Pricing |
| **AWS Secrets Manager** | $0.40 per secret-month `[QUOTED PRICE]` | AWS Secrets Manager |
| **Amazon Route 53 Hosted Zone** | $0.50 per hosted zone-month `[QUOTED PRICE]` | Route 53 Pricing |

---

## 2. Environment Cost Models

### 2.1 Development Environment (Local)
* **Compute / Database / Redis / S3:** Running on local Docker Desktop / MinIO / PostgreSQL / Redis.
* **Monthly Infrastructure Cost:** **$0.00**

---

### 2.2 Staging Environment (Cloud Replica)
*Goal: Provide a production-identical testing environment with cost optimizations (Single-AZ, scaled-down instances, single NAT Gateway).*

| Component | Sizing & Configuration | Estimated Monthly Cost |
|---|---|---|
| **Frontend Container** | 1 Fargate task (0.25 vCPU, 512 MB RAM) | $9.00 `[ESTIMATE]` |
| **API Container** | 1 Fargate task (0.5 vCPU, 1 GB RAM) | $18.00 `[ESTIMATE]` |
| **Media Worker** | 1 Fargate task (1.0 vCPU, 2 GB RAM, 20 GB disk) | $36.00 `[ESTIMATE]` |
| **Publishing Worker** | 1 Fargate task (0.25 vCPU, 512 MB RAM) | $9.00 `[ESTIMATE]` |
| **Scheduler Task** | 1 Fargate task (0.25 vCPU, 512 MB RAM) | $9.00 `[ESTIMATE]` |
| **RDS PostgreSQL 16** | `db.t4g.small` (Single-AZ, 20 GB gp3) | $25.66 `[ESTIMATE]` |
| **ElastiCache Valkey** | `cache.t4g.micro` (Single-Node, 0.5 GB) | $9.34 `[ESTIMATE]` |
| **Application Load Balancer**| 1 Shared ALB (Minimal LCUs) | $18.00 `[ESTIMATE]` |
| **NAT Gateway** | 1 Shared NAT Gateway in AZ-a | $35.00 `[ESTIMATE]` |
| **Storage (S3 / R2)** | 50 GB test media + logs | $1.50 `[USAGE-DEPENDENT]` |
| **Secrets Manager** | 10 Staging Secrets | $4.00 `[QUOTED PRICE]` |
| **CloudWatch Logs** | 5 GB staging logs | $2.50 `[USAGE-DEPENDENT]` |
| **TOTAL STAGING BASELINE** | — | **~$177.00 / month** `[ESTIMATE]` |

*(Note: Staging compute can be scheduled to sleep outside business hours via AWS EventBridge to reduce monthly staging compute by ~60%, bringing staging down to ~$95/mo).*

---

### 2.3 Production Environment — Baseline (Small SaaS)
*Profile: 50 workspaces, ~300 video uploads/month, ~900 publishing jobs/month.*

| Component | Specification | Monthly Cost |
|---|---|---|
| **Frontend** | 1 Fargate task (0.5 vCPU, 1 GB RAM) | $18.00 `[ESTIMATE]` |
| **API** | 2 Fargate tasks (0.5 vCPU, 1 GB RAM Multi-AZ) | $36.00 `[ESTIMATE]` |
| **Media Worker** | 1 Fargate task (2 vCPU, 4 GB RAM, 30 GB disk) | $73.00 `[ESTIMATE]` |
| **Publishing Worker** | 1 Fargate task (0.5 vCPU, 512 MB RAM) | $16.00 `[ESTIMATE]` |
| **Scheduler** | 1 Fargate task (0.25 vCPU, 512 MB RAM) | $9.00 `[ESTIMATE]` |
| **RDS PostgreSQL 16** | `db.t4g.small` Multi-AZ (High Availability, 50 GB gp3) | $52.50 `[ESTIMATE]` |
| **ElastiCache Valkey** | `cache.t4g.micro` Multi-AZ with Auto-Failover | $18.68 `[ESTIMATE]` |
| **Application Load Balancer**| Public ALB with 2 Target Groups | $20.00 `[ESTIMATE]` |
| **NAT Gateway** | 1 NAT Gateway (Production) | $35.00 `[ESTIMATE]` |
| **Object Storage (S3 / R2)** | 100 GB media storage + operations | $2.50 `[USAGE-DEPENDENT]` |
| **Egress Bandwidth** | 50 GB outbound video publishing traffic | $4.50 `[USAGE-DEPENDENT]` |
| **Secrets Manager** | 12 Production Secrets | $4.80 `[QUOTED PRICE]` |
| **Observability (CloudWatch)**| Logs, Alarms, Metrics | $10.00 `[ESTIMATE]` |
| **Route 53 & ACM** | Hosted Zone + SSL Certificates | $0.50 `[QUOTED PRICE]` |
| **TOTAL PROD BASELINE** | — | **~$300.48 / month** `[ESTIMATE]` |

---

### 2.4 Production Environment — Growth Tier
*Profile: 500 workspaces, ~5,000 video uploads/month, ~20,000 publishing jobs/month.*

* **Compute (Autoscaling Fargate Fleet):**
  * Frontend: 2 tasks $\implies$ $36.00/mo
  * API: 3 tasks $\implies$ $54.00/mo
  * Media Workers: Avg 3 tasks $\implies$ $219.00/mo
  * Publishing Workers: Avg 3 tasks $\implies$ $48.00/mo
  * Scheduler: 1 task $\implies$ $9.00/mo
* **Database & Cache:**
  * RDS PostgreSQL: `db.t4g.medium` Multi-AZ + RDS Proxy + 200 GB gp3 $\implies$ $128.00/mo
  * ElastiCache Valkey: `cache.t4g.small` Multi-AZ $\implies$ $30.00/mo
* **Networking & Edge:**
  * ALB (Higher LCUs) $\implies$ $32.00/mo
  * CloudFront CDN $\implies$ $15.00/mo
  * Dual NAT Gateways (Multi-AZ) $\implies$ $70.00/mo
* **Storage & Bandwidth:**
  * Object Storage (1 TB media accumulation) $\implies$ $23.00/mo (or $15.00 on R2)
  * Egress Bandwidth (1 TB video publishing via NAT GW) $\implies$ $90.00/mo
* **Operational:** Secrets, CloudWatch, Sentry APM $\implies$ $40.00/mo
* **TOTAL PRODUCTION GROWTH TIER:** **~$794.00 / month** `[ESTIMATE]`

---

### 2.5 Production Environment — High Workload Tier
*Profile: 2,500 workspaces, ~50,000 video uploads/month, ~250,000 publishing jobs/month.*

* **Compute:** Scaled Fargate Fleet (API, Frontend, Autoscaled Workers) $\implies$ ~$850.00/mo
* **Database:** `db.r6g.xlarge` Multi-AZ + 1 Read Replica + RDS Proxy + 1 TB gp3 $\implies$ ~$680.00/mo
* **Cache:** `cache.m6g.large` Multi-AZ Valkey Cluster $\implies$ ~$100.00/mo
* **Networking & Egress:** Dual NAT Gateways, CloudFront, Egress (12.5 TB outbound) $\implies$ ~$850.00/mo
* **Storage:** 10 TB Media Storage (S3 Standard / Intelligent Tiering) $\implies$ ~$210.00/mo
* **Operational:** Full APM, CloudWatch, Secrets $\implies$ ~$120.00/mo
* **TOTAL PRODUCTION HIGH WORKLOAD:** **~$2,810.00 / month** `[ESTIMATE]`

---

## 3. High-Leverage Cost Optimization Levers

1. **Storage Optimization (Cloudflare R2 Hybrid):**  
   Routing media storage to Cloudflare R2 instead of Amazon S3 eliminates all egress fees on video delivery to social platforms. At 12.5 TB monthly egress, this saves **$1,125.00 / month** on high tier workloads.
2. **Compute Savings Plans:**  
   Committing to a 1-year AWS Compute Savings Plan for baseline Fargate tasks yields an immediate **28% to 35% discount** on hourly compute.
3. **Valkey Engine Adoption:**  
   Using Valkey 7.2 instead of Redis OSS on ElastiCache saves **20%** on managed in-memory caching nodes with identical protocol behavior.
4. **Gateway VPC Endpoint for S3:**  
   Attaching the free AWS Gateway VPC Endpoint for S3 completely eliminates NAT Gateway data processing fees ($0.045/GB) when media workers download source videos.
