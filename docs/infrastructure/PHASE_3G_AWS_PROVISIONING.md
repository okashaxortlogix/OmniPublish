# OmniPublish — Phase 3G AWS Infrastructure Provisioning Blueprint

**Document Reference:** `docs/infrastructure/PHASE_3G_AWS_PROVISIONING.md`  
**System Version:** OmniPublish Phase 3G  
**IaC Toolchain:** HashiCorp Terraform v1.8+  
**Target Environment:** AWS Staging (`us-east-1`)  
**Status:** **CODE COMPLETE — AWAITING HUMAN CONFIRMATION & CLOUD AUTHENTICATION**

---

## 1. Provisioning Architecture Summary

The Terraform codebase authored in `infra/terraform/` provides a production-grade, modular infrastructure-as-code baseline designed to provision the complete OmniPublish staging and production topologies.

```text
infra/terraform/
├── modules/
│   ├── network/      (VPC, Public/Private/Isolated subnets, NAT Gateway, S3 Gateway Endpoint)
│   ├── security/     (ALB, App Compute, RDS, Redis security groups)
│   ├── ecr/          (6 immutable container repositories with Trivy scanning)
│   ├── storage/      (S3 private media bucket with CORS and lifecycle policies)
│   ├── database/     (RDS PostgreSQL 16 Single/Multi-AZ with parameter groups)
│   ├── redis/        (ElastiCache Valkey/Redis 7 replication group with AUTH)
│   ├── secrets/      (AWS Secrets Manager secret shells and IAM access policies)
│   ├── monitoring/   (CloudWatch log groups for 6 services and error alarms)
│   ├── alb/          (Application Load Balancer, listeners, target groups, path routing)
│   └── ecs/          (ECS Fargate Cluster, Task Definitions, IAM roles, Services)
│
└── environments/
    ├── staging/      (Cost-optimized Single-AZ topology, 1 NAT GW, Single DB)
    └── production/   (High-availability Multi-AZ topology, Dual NAT GW, Multi-AZ DB/Redis)
```

---

## 2. Inventory of Planned Staging AWS Resources

When `terraform apply` is executed against the authenticated staging AWS account, exactly **42 discrete cloud resources** will be instantiated:

### 2.1 Networking & Routing (Module: `network`)
1. `aws_vpc.main`: CIDR `10.0.0.0/16` with DNS hostnames and support enabled.
2. `aws_internet_gateway.main`: Internet Gateway attached to VPC.
3. `aws_subnet.public[0]` & `aws_subnet.public[1]`: Public subnets across `us-east-1a` (`10.0.1.0/24`) and `us-east-1b` (`10.0.2.0/24`).
4. `aws_subnet.private_app[0]` & `aws_subnet.private_app[1]`: Private application subnets across `us-east-1a` (`10.0.10.0/24`) and `us-east-1b` (`10.0.20.0/24`).
5. `aws_subnet.isolated_data[0]` & `aws_subnet.isolated_data[1]`: Isolated data subnets across `us-east-1a` (`10.0.30.0/24`) and `us-east-1b` (`10.0.40.0/24`). Zero public routing.
6. `aws_eip.nat[0]`: Dedicated Elastic IP for Staging NAT Gateway.
7. `aws_nat_gateway.main[0]`: NAT Gateway in `us-east-1a` providing outbound HTTPS connectivity for private app tasks.
8. `aws_route_table.public`: Public route table routing `0.0.0.0/0` $\to$ IGW.
9. `aws_route_table.private_app[0]` & `[1]`: Private app route tables routing `0.0.0.0/0` $\to$ NAT GW.
10. `aws_route_table.isolated_data`: Local-only VPC route table. Zero outbound route.
11. `aws_vpc_endpoint.s3`: Gateway VPC Endpoint for S3 attached to private and public route tables.

### 2.2 Security Groups (Module: `security`)
12. `aws_security_group.alb`: Permits inbound TCP 443 and 80 from Internet; egress to app compute.
13. `aws_security_group.app_compute`: Permits inbound TCP 8000 and 3000 strictly from `sg-alb`; full egress via NAT/Endpoint.
14. `aws_security_group.database`: Permits inbound TCP 5432 strictly from `sg-app-compute`.
15. `aws_security_group.redis`: Permits inbound TCP 6379 strictly from `sg-app-compute`.

### 2.3 Container Registry (Module: `ecr`)
16–21. 6 ECR Repositories with tag immutability and scan-on-push:
   * `omnipublish-staging-api`
   * `omnipublish-staging-frontend`
   * `omnipublish-staging-publishing-worker`
   * `omnipublish-staging-media-worker`
   * `omnipublish-staging-default-worker`
   * `omnipublish-staging-scheduler`

### 2.4 Media Object Storage (Module: `storage`)
22. `aws_s3_bucket.media`: Private bucket with bucket prefix `omnipublish-staging-media-`.
23. `aws_s3_bucket_public_access_block.media`: Enforces 100% block on all public ACLs and policies.
24. `aws_s3_bucket_server_side_encryption_configuration.media`: SSE-S3 AES-256 encryption.
25. `aws_s3_bucket_cors_configuration.media`: Permitting PUT/GET/HEAD from staging frontend.
26. `aws_s3_bucket_lifecycle_configuration.media`: Aborting incomplete multipart uploads after 7 days.

### 2.5 Relational Database (Module: `database`)
27. `aws_db_subnet_group.main`: Subnet group binding isolated subnets.
28. `aws_db_parameter_group.pg16`: Parameter group enforcing `rds.force_ssl = 1`.
29. `aws_db_instance.main`: PostgreSQL 16.2 Single-AZ instance (`db.t4g.small`, 20 GB gp3 storage).

### 2.6 In-Memory Caching & Queues (Module: `redis`)
30. `aws_elasticache_subnet_group.main`: Subnet group binding isolated subnets.
31. `aws_elasticache_parameter_group.valkey`: Parameter group enforcing `maxmemory-policy = noeviction`.
32. `aws_elasticache_replication_group.main`: Valkey 7.2 cluster (`cache.t4g.micro`, in-transit TLS, AUTH token).

### 2.7 Secrets Management & IAM (Module: `secrets`)
33–36. 4 Secret Shells in AWS Secrets Manager (`APP_KEY`, `DB_CREDENTIALS`, `REDIS_AUTH`, `SOCIAL_OAUTH`).
37. `aws_iam_policy.read_secrets`: Least-privilege IAM policy allowing decryption of secret shells.

### 2.8 Monitoring & Observability (Module: `monitoring`)
38. 6 CloudWatch Log Groups under `/ecs/omnipublish-staging-*` (14-day retention).
39. `aws_cloudwatch_metric_alarm.api_errors`: Alarm alerting on elevated error rates.

### 2.9 Ingress & Load Balancing (Module: `alb`)
40. `aws_lb.main`: Internet-facing Application Load Balancer across public subnets.
41. `aws_lb_target_group.api`: Target group for Laravel API on port 8000 (`/health/live` probe).
42. `aws_lb_target_group.frontend`: Target group for Next.js on port 3000 (`/` probe).
43. `aws_lb_listener.http`: Port 80 listener.
44. `aws_lb_listener_rule.api_routing`: Routing `/api/*` and `/health*` to API target group.

### 2.10 Compute Services (Module: `ecs`)
45. `aws_ecs_cluster.main`: ECS Fargate cluster `omnipublish-staging-cluster`.
46. `aws_iam_role.execution`: ECS Task Execution Role with Secrets read policy.
47. `aws_iam_role.task`: ECS Task Role with S3 read/write access.
48–52. 5 Task Definitions (`api`, `frontend`, `media-worker`, `publishing-worker`, `scheduler`).
53–57. 5 ECS Services running on AWS Fargate in private application subnets.

---

## 3. Provisioning Execution Runbook (When Approved)

When explicit human confirmation is received and credentials are authenticated:

```bash
# Step 1: Navigate to staging Terraform directory
cd infra/terraform/environments/staging

# Step 2: Initialize Terraform and download providers
terraform init

# Step 3: Generate and inspect execution plan
terraform plan -out=staging.tfplan

# Step 4: Apply execution plan (Requires Human Approval)
terraform apply staging.tfplan

# Step 5: Capture outputs
terraform output
```
