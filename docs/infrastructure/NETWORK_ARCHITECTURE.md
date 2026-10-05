# OmniPublish — Production Network Architecture & Security Boundaries

**Document Reference:** `docs/infrastructure/NETWORK_ARCHITECTURE.md`  
**System Version:** OmniPublish Phase 3GA  
**Target Environment:** AWS VPC (us-east-1 / multi-AZ)  
**Standard:** Critical Honesty Principle (Zero Public Database or Cache Exposure)

---

## 1. Network Topology Overview

OmniPublish enforces a multi-tier Virtual Private Cloud (VPC) network architecture that segregates public ingress from internal application compute and isolated database infrastructure.

Under no circumstances are the PostgreSQL database, Redis cluster, or background queue workers directly exposed to the public Internet.

```text
                                  THE PUBLIC INTERNET
                                           │
                                    HTTPS Port 443
                                           │
                                           ▼
                   ┌──────────────────────────────────────────────┐
                   │               AWS VPC (10.0.0.0/16)          │
                   │                                              │
                   │  PUBLIC SUBNETS (10.0.1.0/24, 10.0.2.0/24)  │
                   │  ┌────────────────────────────────────────┐  │
                   │  │   AWS Application Load Balancer (ALB)  │  │
                   │  │   [Optional AWS WAF Web ACL Protection]│  │
                   │  │   NAT Gateways (AZ-a & AZ-b)           │  │
                   │  └───────────────────┬────────────────────┘  │
                   │                      │                       │
                   │                      ▼ (Internal HTTP/TLS)   │
                   │  PRIVATE APP SUBNETS (10.0.10.0/24, 20.0/24) │
                   │  ┌────────────────────────────────────────┐  │
                   │  │   ECS Fargate: Next.js Frontend        │  │
                   │  │   ECS Fargate: Laravel 12 API          │  │
                   │  │   ECS Fargate: Publishing Workers      │  │
                   │  │   ECS Fargate: Media Processing Workers│  │
                   │  │   ECS Fargate: Default Workers         │  │
                   │  │   ECS Fargate: Scheduler Daemon        │  │
                   │  └───────────┬─────────────────┬──────────┘  │
                   │              │                 │             │
                   │     Port 5432│         Port 6379│             │
                   │              ▼                 ▼             │
                   │  ISOLATED DATA SUBNETS (10.0.30.0/24, 40.0/24)│
                   │  ┌────────────────────────────────────────┐  │
                   │  │   Amazon RDS PostgreSQL 16 (Multi-AZ)  │  │
                   │  │   Amazon ElastiCache Redis / Valkey    │  │
                   │  └────────────────────────────────────────┘  │
                   └──────────────────────────────────────────────┘
```

---

## 2. Subnet Layout & IP Allocation Plan

The VPC is allocated CIDR block `10.0.0.0/16` for Production (`10.1.0.0/16` for Staging) spanning two Availability Zones (AZ-a and AZ-b) for high availability:

| Subnet Identifier | CIDR Block | Availability Zone | Routing / Gateway | Deployed Services |
|---|---|---|---|---|
| **Public-Subnet-A** | `10.0.1.0/24` | `us-east-1a` | Internet Gateway (`igw-xxxx`) | ALB Listener, NAT Gateway A |
| **Public-Subnet-B** | `10.0.2.0/24` | `us-east-1b` | Internet Gateway (`igw-xxxx`) | ALB Listener, NAT Gateway B |
| **Private-App-Subnet-A** | `10.0.10.0/24` | `us-east-1a` | Route to NAT Gateway A | ECS Fargate Tasks (API, Frontend, Workers) |
| **Private-App-Subnet-B** | `10.0.20.0/24` | `us-east-1b` | Route to NAT Gateway B | ECS Fargate Tasks (API, Frontend, Workers) |
| **Isolated-Data-Subnet-A** | `10.0.30.0/24` | `us-east-1a` | Local VPC Only (Zero IGW/NAT routes)| RDS Primary, ElastiCache Node 1 |
| **Isolated-Data-Subnet-B** | `10.0.40.0/24` | `us-east-1b` | Local VPC Only (Zero IGW/NAT routes)| RDS Standby, ElastiCache Node 2 |

---

## 3. Ingress & Egress Traffic Rules

### 3.1 Ingress Path
1. **User HTTPS Request:** Resolves via Route 53 directly to Application Load Balancer (ALB) via an Alias A-record.
2. **TLS Edge Termination:** ALB terminates client TLS 1.3 using AWS Certificate Manager (ACM) certificate for `*.omnipublish.com`. (Optional AWS WAF Web ACL inspects Layer 7 requests directly at the ALB).
3. **Path-Based Routing at ALB:**
   * `/api/*`, `/health*`, `/version*` $\implies$ Forward to `LaravelAPI` target group (Port 8000).
   * All other routes (`/*`) $\implies$ Forward to `NextJS` target group (Port 3000).
4. **No Public Direct Access:** Tasks in `Private-App-Subnets` have zero public IPv4 addresses; they accept connections strictly from the ALB security group.
5. **Phase 4 CDN Consideration:** CloudFront CDN edge distribution can be layered in front of the ALB in Phase 4 if global static asset offloading is required. For MVP, direct ALB routing avoids cross-tenant caching risks and presigned URL incompatibilities.

### 3.2 Egress Path
1. **Third-Party Social APIs:** Publishing workers (`publishing-worker`) initiate outbound HTTPS connections to:
   * Google Cloud: `https://www.googleapis.com` (YouTube Data API v3).
   * TikTok: `https://open.tiktokapis.com` (Direct Post API).
   * Meta: `https://graph.facebook.com` (Instagram Graph API).
2. **NAT Gateway Routing:** Outbound traffic from private app subnets routes through the regional NAT Gateway.
3. **S3 VPC Endpoint Optimization:** To avoid paying NAT Gateway data transfer fees on internal media uploads/downloads, an **AWS Gateway VPC Endpoint for S3** (`com.amazonaws.us-east-1.s3`) is attached to private subnet route tables. All traffic between Media Workers and S3 stays on the AWS private backbone at zero data transfer cost.

---

## 4. Security Group Matrix

Security Groups act as stateful firewalls enforcing the principle of least privilege:

```text
[ Internet ]
     │ Port 443 (HTTPS)
     ▼
[ sg-alb ]
     │ Port 8000 / 3000 (Source: sg-alb)
     ▼
[ sg-app-compute ]
  ├── Outbound Port 5432 (Target: sg-database)
  ├── Outbound Port 6379 (Target: sg-redis)
  └── Outbound Port 443  (Target: 0.0.0.0/0 via NAT GW)
     │                     │
     ▼ Port 5432           ▼ Port 6379
[ sg-database ]       [ sg-redis ]
```

### Detailed Rules Definition

#### 1. `sg-alb` (Application Load Balancer Security Group)
* **Inbound:**
  * Protocol: `TCP`, Port: `443`, Source: `CloudFront IP Ranges` (or `0.0.0.0/0` if CloudFront not mandatory for staging).
  * Protocol: `TCP`, Port: `80`, Source: `0.0.0.0/0` (Redirects HTTP $\to$ HTTPS).
* **Outbound:**
  * Protocol: `TCP`, Port: `8000`, Target: `sg-app-compute` (Laravel API).
  * Protocol: `TCP`, Port: `3000`, Target: `sg-app-compute` (Next.js Frontend).

#### 2. `sg-app-compute` (ECS Fargate Tasks Security Group)
* **Inbound:**
  * Protocol: `TCP`, Port: `8000`, Source: `sg-alb`.
  * Protocol: `TCP`, Port: `3000`, Source: `sg-alb`.
* **Outbound:**
  * Protocol: `TCP`, Port: `5432`, Target: `sg-database` (PostgreSQL).
  * Protocol: `TCP`, Port: `6379`, Target: `sg-redis` (Redis/Valkey).
  * Protocol: `TCP`, Port: `443`, Target: `0.0.0.0/0` (HTTPS via NAT GW to Social APIs & S3).

#### 3. `sg-database` (RDS PostgreSQL Security Group)
* **Inbound:**
  * Protocol: `TCP`, Port: `5432`, Source: `sg-app-compute` (Only app compute containers).
* **Outbound:**
  * None required (Stateful responses handled automatically).

#### 4. `sg-redis` (ElastiCache Redis Security Group)
* **Inbound:**
  * Protocol: `TCP`, Port: `6379`, Source: `sg-app-compute` (Only app compute containers).
* **Outbound:**
  * None required.

---

## 5. Administrative & Deployment Access

1. **Zero SSH Bastion Policy:**
   * Traditional SSH jump-boxes / bastion hosts are explicitly excluded.
   * Debugging or operational execution on ECS tasks is conducted via **AWS Systems Manager (SSM) Session Manager** and **ECS Exec** using IAM-authenticated roles with full audit logging.
2. **Database Migrations Execution:**
   * Schema migrations (`php artisan migrate --force`) run either:
     a. As an ephemeral one-shot ECS Fargate task during the CI/CD deployment pipeline.
     b. Within the API container startup lifecycle guarded by a distributed database advisory lock.
   * Developers cannot connect directly to production PostgreSQL over the public Internet.
3. **Database Maintenance / Debug Access:**
   * If manual DBA inspection is required for incident triage, access is granted exclusively through a temporary SSM tunnel session with MFA authorization, connecting via local port forwarding through an internal endpoint.
