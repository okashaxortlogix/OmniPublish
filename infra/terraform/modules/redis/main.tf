# =========================================================================
# OmniPublish In-Memory Module — Amazon ElastiCache Valkey / Redis 7
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

variable "environment" {
  type        = string
  description = "Deployment environment"
}

variable "subnet_ids" {
  type        = list(string)
  description = "Isolated data subnet IDs"
}

variable "security_group_id" {
  type        = string
  description = "Redis security group ID"
}

variable "node_type" {
  type        = string
  default     = "cache.t4g.micro"
  description = "ElastiCache node instance type"
}

variable "multi_az_enabled" {
  type        = bool
  default     = false
  description = "Enable automatic multi-AZ failover (recommended for production)"
}

variable "auth_token" {
  type        = string
  sensitive   = true
  description = "AUTH token for Redis client authentication"
}

# 1. Subnet Group
resource "aws_elasticache_subnet_group" "main" {
  name       = "omnipublish-${var.environment}-redis-subnets"
  subnet_ids = var.subnet_ids

  tags = {
    Name        = "omnipublish-${var.environment}-redis-subnet-group"
    Environment = var.environment
  }
}

# 2. Valkey / Redis Parameter Group
resource "aws_elasticache_parameter_group" "valkey" {
  name   = "omnipublish-${var.environment}-valkey-params"
  family = "valkey7"

  parameter {
    name  = "maxmemory-policy"
    value = "noeviction" # Never drop in-flight jobs in DB 0
  }

  parameter {
    name  = "timeout"
    value = "300"
  }

  tags = {
    Name        = "omnipublish-${var.environment}-valkey-params"
    Environment = var.environment
  }
}

# 3. ElastiCache Replication Group
resource "aws_elasticache_replication_group" "main" {
  replication_group_id = "omnipublish-${var.environment}-redis"
  description          = "Redis/Valkey replication group for OmniPublish queues and locks"
  engine               = "valkey"
  engine_version       = "7.2"
  node_type            = var.node_type
  port                 = 6379

  subnet_group_name    = aws_elasticache_subnet_group.main.name
  security_group_ids   = [var.security_group_id]
  parameter_group_name = aws_elasticache_parameter_group.valkey.name

  num_cache_clusters         = var.multi_az_enabled ? 2 : 1
  automatic_failover_enabled = var.multi_az_enabled
  multi_az_enabled           = var.multi_az_enabled

  transit_encryption_enabled = true # In-transit TLS 1.3
  at_rest_encryption_enabled = true # Encryption at rest
  auth_token                 = var.auth_token

  snapshot_retention_limit = var.environment == "production" ? 7 : 0
  snapshot_window          = "02:00-03:00"
  maintenance_window       = "Sun:03:30-Sun:04:30"

  tags = {
    Name        = "omnipublish-${var.environment}-redis"
    Environment = var.environment
  }
}

# Outputs
output "primary_endpoint" {
  value = aws_elasticache_replication_group.main.primary_endpoint_address
}

output "port" {
  value = aws_elasticache_replication_group.main.port
}
