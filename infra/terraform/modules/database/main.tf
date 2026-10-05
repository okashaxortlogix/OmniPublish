# =========================================================================
# OmniPublish Database Module — Amazon RDS PostgreSQL 16
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
  description = "Database security group ID"
}

variable "instance_class" {
  type        = string
  default     = "db.t4g.small"
  description = "RDS instance class"
}

variable "allocated_storage" {
  type        = number
  default     = 20
  description = "Allocated storage in GB"
}

variable "max_allocated_storage" {
  type        = number
  default     = 100
  description = "Storage auto-scaling threshold in GB"
}

variable "engine_version" {
  type        = string
  default     = "16.4"
  description = "PostgreSQL engine version (16.4+ includes essential security & stability fixes)"
}

variable "performance_insights_enabled" {
  type        = bool
  default     = false
  description = "Enable RDS Performance Insights for query telemetry"
}

variable "multi_az" {
  type        = bool
  default     = false
  description = "Enable Multi-AZ synchronous replication (recommended for production)"
}

variable "database_name" {
  type        = string
  default     = "omnipublish"
  description = "Default database name"
}

variable "database_username" {
  type        = string
  default     = "omnipublish_app"
  description = "Master database username"
}

variable "database_password" {
  type        = string
  sensitive   = true
  description = "Master database password"
}

variable "backup_retention_period" {
  type        = number
  default     = 7
  description = "Automated backup retention days (7 for staging, 35 for production)"
}

variable "deletion_protection" {
  type        = bool
  default     = false
  description = "Prevent accidental instance deletion"
}

# 1. RDS DB Subnet Group
resource "aws_db_subnet_group" "main" {
  name_prefix = "omnipublish-${var.environment}-db-subnets-"
  subnet_ids  = var.subnet_ids

  tags = {
    Name        = "omnipublish-${var.environment}-db-subnet-group"
    Environment = var.environment
  }
}

# 2. PostgreSQL 16 Parameter Group
resource "aws_db_parameter_group" "pg16" {
  name_prefix = "omnipublish-${var.environment}-pg16-"
  family      = "postgres16"
  description = "Optimized parameters for OmniPublish PostgreSQL 16"

  parameter {
    name  = "rds.force_ssl"
    value = "1" # Force SSL/TLS on all client connections
  }

  parameter {
    name  = "shared_preload_libraries"
    value = "pg_stat_statements"
  }

  tags = {
    Name        = "omnipublish-${var.environment}-pg16-params"
    Environment = var.environment
  }

  lifecycle {
    create_before_destroy = true
  }
}

# 3. RDS PostgreSQL Instance
resource "aws_db_instance" "main" {
  identifier_prefix     = "omnipublish-${var.environment}-db-"
  engine                = "postgres"
  engine_version        = var.engine_version
  instance_class        = var.instance_class
  allocated_storage     = var.allocated_storage
  max_allocated_storage = var.max_allocated_storage
  storage_type          = "gp3"
  storage_encrypted     = true

  db_name  = var.database_name
  username = var.database_username
  password = var.database_password
  port     = 5432

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [var.security_group_id]
  parameter_group_name   = aws_db_parameter_group.pg16.name

  multi_az            = var.multi_az
  publicly_accessible = false # ZERO PUBLIC ACCESS

  performance_insights_enabled          = var.performance_insights_enabled
  performance_insights_retention_period = var.performance_insights_enabled ? 7 : null

  backup_retention_period    = var.backup_retention_period
  backup_window              = "03:00-04:00" # Low-traffic UTC window
  maintenance_window         = "Sun:04:30-Sun:05:30"
  auto_minor_version_upgrade = true
  deletion_protection        = var.deletion_protection
  skip_final_snapshot        = var.environment == "staging" ? true : false
  final_snapshot_identifier  = var.environment == "production" ? "omnipublish-prod-final-snapshot" : null

  tags = {
    Name        = "omnipublish-${var.environment}-db"
    Environment = var.environment
  }
}

# Outputs
output "endpoint" {
  value = aws_db_instance.main.address
}

output "port" {
  value = aws_db_instance.main.port
}

output "database_name" {
  value = aws_db_instance.main.db_name
}

output "database_username" {
  value = aws_db_instance.main.username
}

output "identifier" {
  value = aws_db_instance.main.identifier
}
