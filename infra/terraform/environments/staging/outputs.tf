# =========================================================================
# OmniPublish Staging — Outputs
# =========================================================================

output "alb_dns_name" {
  description = "Public DNS hostname of the Application Load Balancer"
  value       = module.alb.dns_name
}

output "s3_bucket_name" {
  description = "Name of the private S3 media bucket"
  value       = module.storage.bucket_name
}

output "database_endpoint" {
  description = "Private RDS PostgreSQL endpoint"
  value       = module.database.endpoint
}

output "redis_endpoint" {
  description = "Private ElastiCache primary endpoint"
  value       = module.redis.primary_endpoint
}

output "ecr_repository_urls" {
  description = "ECR image repository URLs for all services"
  value       = module.ecr.repository_urls
}
