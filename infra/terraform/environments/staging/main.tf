# =========================================================================
# OmniPublish Staging — Root Infrastructure Manifest
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

# 1. Network Module (Single NAT Gateway for cost-optimized staging)
module "network" {
  source             = "../../modules/network"
  environment        = var.environment
  vpc_cidr           = var.vpc_cidr
  availability_zones = var.availability_zones
  single_nat_gateway = true
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

# 4. Storage Module (S3 Private Media Bucket)
module "storage" {
  source      = "../../modules/storage"
  environment = var.environment
  cors_allowed_origins = [
    "https://staging.omnipublish.com",
    "http://localhost:3000"
  ]
}

# 5. Database Module (RDS PostgreSQL 16 Single-AZ for Staging)
module "database" {
  source                  = "../../modules/database"
  environment             = var.environment
  subnet_ids              = module.network.isolated_data_subnet_ids
  security_group_id       = module.security.database_security_group_id
  engine_version          = "16.4"
  instance_class          = "db.t4g.small"
  allocated_storage       = 20
  multi_az                = false
  database_password       = var.db_password
  backup_retention_period = 7
  deletion_protection     = false
}

# 6. Redis / Valkey Module (ElastiCache Single-Node for Staging)
module "redis" {
  source            = "../../modules/redis"
  environment       = var.environment
  subnet_ids        = module.network.isolated_data_subnet_ids
  security_group_id = module.security.redis_security_group_id
  node_type         = "cache.t4g.micro"
  multi_az_enabled  = false
  auth_token        = var.redis_auth_token
}

# 7. Secrets Module
module "secrets" {
  source      = "../../modules/secrets"
  environment = var.environment
}

# 8. Ingress Module (ALB)
module "alb" {
  source            = "../../modules/alb"
  environment       = var.environment
  vpc_id            = module.network.vpc_id
  public_subnet_ids = module.network.public_subnet_ids
  security_group_id = module.security.alb_security_group_id
}

# 9. Monitoring Module
module "monitoring" {
  source                 = "../../modules/monitoring"
  environment            = var.environment
  retention_in_days      = 14 # 14 days retention for staging
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
