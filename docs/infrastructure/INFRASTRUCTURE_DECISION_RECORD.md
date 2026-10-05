# OmniPublish — Infrastructure Decision Record (ADR)

**Document Reference:** `docs/infrastructure/INFRASTRUCTURE_DECISION_RECORD.md`  
**Decision Title:** Selection of AWS Serverless Container Architecture & Terraform IaC  
**Status:** ACCEPTED  
**Deciders:** OmniPublish Core Architecture Team  
**Date:** October 2026

---

## 1. Context & Problem Statement

OmniPublish is transitioning from a validated application codebase to a production-grade multi-tenant SaaS deployment. The system combines:
1. Low-latency REST API requests (Laravel 12 + Next.js 15).
2. CPU/disk-intensive video processing (FFmpeg/FFprobe with up to 2 GB source files).
3. Network-bound asynchronous publishing (YouTube, TikTok, Instagram chunked resumable uploads).
4. Strictly isolated multi-tenant relational persistence (PostgreSQL 16).
5. High-durability in-memory queues and distributed locks (Redis 7 / Valkey 7.2).
6. Multi-gigabyte binary asset storage (S3-compatible API).

We must select an infrastructure provider, compute architecture, and Infrastructure-as-Code (IaC) framework that minimize operational maintenance, eliminate static credential risks, support independent worker scaling, and ensure reproducible staging/production parity.

---

## 2. Decision Summary

We have selected **Amazon Web Services (AWS)** using a **Serverless Container (ECS Fargate) + Managed Data Tier** reference architecture, provisioned exclusively via **Terraform**:

```text
Infrastructure Stack:
• Compute: AWS ECS Fargate (Serverless OCI Containers)
• Relational DB: Amazon RDS for PostgreSQL 16 (Multi-AZ + RDS Proxy)
• Caching & Queues: Amazon ElastiCache for Valkey 7.2 / Redis (Multi-AZ)
• Object Storage: Amazon S3 Standard (with Cloudflare R2 Hybrid Egress Option)
• Ingress & Edge: AWS ALB + Amazon CloudFront CDN + Route 53
• Secrets & IAM: AWS Secrets Manager + ECS Task IAM Roles
• IaC Engine: HashiCorp Terraform (v1.8+)
```

---

## 3. Rationale & Alternatives Considered

### 3.1 Why AWS ECS Fargate Over Kubernetes (EKS / Self-Hosted K8s)?
* **Overhead:** Managing an EKS control plane incurs $72/month per cluster in base control plane fees plus substantial operational overhead (managing node pools, CNI plugins, ingress controllers, cert-manager, cluster upgrades).
* **Fargate Right-Sizing:** ECS Fargate provides native, serverless container execution with zero virtual machines to patch, configure, or secure. Each media worker task receives a dedicated micro-VM with dedicated CPU and NVMe scratch disk.
* **Direct Docker Alignment:** OmniPublish already maintains production Dockerfile targets (`api`, `publishing-worker`, `media-worker`, `default-worker`, `scheduler`, `frontend`). Fargate runs these exact images natively.

### 3.2 Why Not Cloudflare-Only or PaaS (Render / Railway)?
* As documented in `docs/infrastructure/PROVIDER_RESEARCH.md`, Cloudflare Workers cannot run FFmpeg binaries or long-running worker daemons due to V8 isolate constraints (128 MB RAM, 30s CPU limit, no POSIX system calls).
* PaaS platforms (Render/Railway) suffer from unpredictable CPU-burst pricing, lack fine-grained autoscaling based on Redis queue depth, and provide coarse single-region networking without true Multi-AZ synchronous database failover.

### 3.3 Why Terraform Over Pulumi or CloudFormation / CDK?
* **Declarative State Management:** Terraform provides explicit, plan-before-apply state visibility (`terraform plan`), essential for auditing infrastructure changes prior to mutation.
* **Maturity & Ecosystem:** The AWS Provider for Terraform (`hashicorp/aws`) is the most battle-tested and widely supported IaC toolchain in enterprise cloud engineering.
* **Provider Independence:** Terraform modules can configure AWS resources and Cloudflare DNS/R2 resources within the same state plane via standard provider plugins.
* **Clean Environment Parity:** Shared reusable modules enforce 100% configuration consistency between `staging` and `production` environments while isolating state files.

---

## 4. Terraform Directory Architecture

The upcoming Phase 3G implementation will utilize the following modular directory layout:

```text
infra/
└── terraform/
    ├── environments/
    │   ├── staging/
    │   │   ├── main.tf              (Instantiates modules with staging sizing)
    │   │   ├── variables.tf         (Staging domain, CIDR, instance sizes)
    │   │   ├── terraform.tfvars     (Staging variables)
    │   │   └── outputs.tf
    │   │
    │   └── production/
    │       ├── main.tf              (Instantiates modules with Multi-AZ sizing)
    │       ├── variables.tf         (Production domains, Multi-AZ flags)
    │       ├── terraform.tfvars     (Production variables)
    │       └── outputs.tf
    │
    └── modules/
        ├── vpc/                     (VPC, Public/Private/Isolated subnets, NAT GW, IGW, S3 Endpoint)
        ├── security/                (Security Groups: ALB, App Compute, RDS, Redis)
        ├── database/               (RDS PostgreSQL 16, Subnet Group, Parameter Group, RDS Proxy)
        ├── redis/                  (ElastiCache Valkey/Redis Replication Group, Subnet Group)
        ├── storage/                (S3 Private Media Bucket, Bucket Policy, CORS, Lifecycle Rules)
        ├── compute/                (ECS Cluster, Fargate Task Definitions, IAM Task Roles, ECS Services)
        ├── alb/                    (Application Load Balancer, Listeners, Target Groups, Health Checks)
        ├── edge/                   (CloudFront Distribution, ACM SSL Certificates, Route 53 Records)
        ├── secrets/                (AWS Secrets Manager secret shells and IAM access policies)
        └── monitoring/             (CloudWatch Log Groups, Alarms, Metric Filters)
```

---

## 5. Non-Provisioning Guarantee

In accordance with Phase 3GA instructions:
* **Zero cloud infrastructure has been provisioned.**
* **Zero cloud billing accounts have been charged.**
* **Zero fake resource IDs or fabricated ARNs have been generated.**
* This record represents the binding architectural decision to be executed upon explicit human authorization in Phase 3G.
