# =========================================================================
# OmniPublish Production — Root Infrastructure Manifest
# =========================================================================

terraform {
  required_version = ">= 1.8.0"
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Project     = "OmniPublish"
      Environment = var.environment
      ManagedBy   = "Terraform"
    }
  }
}

# 1. Network Module (Dual Multi-AZ NAT Gateways for Production)
module "network" {
  source             = "../../modules/network"
  environment        = var.environment
  vpc_cidr           = var.vpc_cidr
  availability_zones = var.availability_zones
  single_nat_gateway = false
}

# 2. Security Module
module "security" {
  source      = "../../modules/security"
  environment = var.environment
  vpc_id      = module.network.vpc_id
}

# 3. ECR Repositories Module
module "ecr" {
  source      = "../../modules/ecr"
  environment = var.environment
}

# 4. Storage Module (S3 Private Media Bucket with Versioning)
module "storage" {
  source      = "../../modules/storage"
  environment = var.environment
  cors_allowed_origins = [
    "https://app.omnipublish.com"
  ]
}

# 5. Database Module (RDS PostgreSQL 16 Multi-AZ with 35-day PITR for Production)
module "database" {
  source                       = "../../modules/database"
  environment                  = var.environment
  subnet_ids                   = module.network.isolated_data_subnet_ids
  security_group_id            = module.security.database_security_group_id
  engine_version               = "16.4"
  instance_class               = "db.t4g.medium"
  allocated_storage            = 50
  max_allocated_storage        = 500
  multi_az                     = true
  database_password            = var.db_password
  backup_retention_period      = 35
  deletion_protection          = true
  performance_insights_enabled = true
}

# 6. Redis / Valkey Module (ElastiCache Multi-AZ with Auto-Failover for Production)
module "redis" {
  source            = "../../modules/redis"
  environment       = var.environment
  subnet_ids        = module.network.isolated_data_subnet_ids
  security_group_id = module.security.redis_security_group_id
  node_type         = "cache.t4g.small"
  multi_az_enabled  = true
  auth_token        = var.redis_auth_token
}

# 7. Secrets Module
module "secrets" {
  source      = "../../modules/secrets"
  environment = var.environment
}

# 8. Ingress Module (ALB with HTTPS)
module "alb" {
  source            = "../../modules/alb"
  environment       = var.environment
  vpc_id            = module.network.vpc_id
  public_subnet_ids = module.network.public_subnet_ids
  security_group_id = module.security.alb_security_group_id
  certificate_arn   = var.certificate_arn
}

# 9. Monitoring Module
module "monitoring" {
  source                 = "../../modules/monitoring"
  environment            = var.environment
  retention_in_days      = 30
  alb_arn_suffix         = module.alb.arn_suffix
  db_instance_identifier = module.database.identifier
}

# 10. Compute Module (ECS Fargate Cluster & Services)
module "ecs" {
  source                    = "../../modules/ecs"
  environment               = var.environment
  vpc_id                    = module.network.vpc_id
  private_subnet_ids        = module.network.private_app_subnet_ids
  security_group_id         = module.security.app_compute_security_group_id
  api_target_group_arn      = module.alb.api_target_group_arn
  frontend_target_group_arn = module.alb.frontend_target_group_arn
  s3_media_bucket_arn       = module.storage.bucket_arn
  s3_media_bucket_name      = module.storage.bucket_name
  secrets_policy_arn        = module.secrets.secrets_read_policy_arn
  app_key_secret_arn        = module.secrets.app_key_secret_arn
  db_credentials_secret_arn = module.secrets.db_credentials_secret_arn
  redis_auth_secret_arn     = module.secrets.redis_auth_secret_arn
  db_endpoint               = module.database.endpoint
  db_name                   = module.database.database_name
  db_user                   = module.database.database_username
  redis_endpoint            = module.redis.primary_endpoint
  image_tag                 = var.image_tag
  ecr_repository_urls       = module.ecr.repository_urls
}
