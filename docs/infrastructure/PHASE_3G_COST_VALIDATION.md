# OmniPublish — Phase 3G Cost Validation Report

**Document Reference:** `docs/infrastructure/PHASE_3G_COST_VALIDATION.md`  
**System Version:** OmniPublish Phase 3G  
**Evaluation Date:** October 2026  
**Pricing Standards:** Official AWS October 2026 Price Books (us-east-1)

---

## 1. Staging Infrastructure Planned Cost vs. Model

The staging Terraform manifest ([infra/terraform/environments/staging/main.tf](file:///e:/Okashaaaaa/Projects/OmniPublish/OmniPublish/infra/terraform/environments/staging/main.tf)) implements a cost-optimized, single-AZ topology designed to minimize ongoing cloud expenditure during pre-production testing:

| Component | Sizing in Terraform Manifest | Estimated Monthly Cost | Sizing Optimization Applied |
|---|---|---|---|
| **VPC & NAT Gateway** | 1 VPC, 6 Subnets, 1 NAT Gateway | ~$35.00 / mo | Single NAT Gateway (saves $35/mo vs multi-AZ dual NAT) |
| **S3 Gateway Endpoint** | Attached to all route tables | **$0.00** | Free; eliminates NAT Gateway fees on S3 video transfers |
| **RDS PostgreSQL 16** | `db.t4g.small` (Single-AZ, 20 GB gp3) | ~$25.66 / mo | Single-AZ deployment for staging |
| **ElastiCache Valkey** | `cache.t4g.micro` (Single-Node, 0.5 GB) | ~$9.34 / mo | Valkey engine (saves 20% vs Redis OSS) |
| **S3 Private Media Bucket** | Standard S3 (Test media $< 50\text{ GB}$) | ~$1.50 / mo | 7-day abort on multipart uploads |
| **Application Load Balancer**| Public ALB with 2 Target Groups | ~$18.00 / mo | Shared ALB routing via path-based listener rules |
| **ECS Fargate Tasks** | 5 tasks (API, Web, 3 Workers) | ~$81.00 / mo | Minimal baseline task sizing |
| **CloudWatch Logs** | 6 Log Groups (14-day retention) | ~$2.50 / mo | 14-day retention (avoids indefinite log accumulation) |
| **Secrets Manager** | 4 Secret Shells | ~$1.60 / mo | Grouped secret shells |
| **TOTAL ESTIMATED STAGING COST**| — | **~$174.60 / month** | (Down to ~$95/mo if compute scheduled off-hours) |

---

## 2. Production Baseline Cost Comparison

The production Terraform manifest ([infra/terraform/environments/production/main.tf](file:///e:/Okashaaaaa/Projects/OmniPublish/OmniPublish/infra/terraform/environments/production/main.tf)) provisions full Multi-AZ redundancy:

| Component | Staging Topology | Production Baseline Topology | Cost Differential |
|---|---|---|---|
| **NAT Gateways** | 1 NAT Gateway ($35/mo) | 2 NAT Gateways ($70/mo) | +$35/mo (High Availability) |
| **RDS PostgreSQL** | `db.t4g.small` Single-AZ ($25.66/mo) | `db.t4g.medium` Multi-AZ ($104.90/mo) | +$79.24/mo (Failover & Scale) |
| **ElastiCache** | `cache.t4g.micro` Single-Node ($9.34/mo)| `cache.t4g.small` Multi-AZ ($29.94/mo) | +$20.60/mo (Auto-failover) |
| **ECS Tasks** | 5 tasks (~$81/mo) | 6 tasks with 2 API replicas (~$153/mo)| +$72/mo (Redundancy) |
| **S3 Storage** | 50 GB (~$1.50/mo) | 100 GB + Versioning (~$3.00/mo) | Usage-dependent |
| **TOTAL MONTHLY BASELINE**| **~$174.60 / mo** | **~$300.48 / mo** | Baseline Enterprise Readiness |

---

## 3. Financial Safety Guarantee

* **Zero Current Cloud Cost Incurred:** Because `terraform apply` has not been run against live cloud accounts, current monthly AWS billing remains **$0.00**.
* **Billing Alerts:** The Terraform monitoring module defines CloudWatch Billing Alarms triggering alerts if monthly spend exceeds **$200.00** for staging or **$400.00** for production.
