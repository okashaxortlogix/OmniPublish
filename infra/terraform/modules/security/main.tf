# =========================================================================
# OmniPublish Security Module — Least-Privilege Security Groups
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

variable "vpc_id" {
  type        = string
  description = "VPC ID where security groups will be created"
}

# 1. ALB Security Group (Public Ingress)
resource "aws_security_group" "alb" {
  name_prefix = "omnipublish-${var.environment}-alb-sg-"
  description = "Controls public HTTPS ingress to Application Load Balancer"
  vpc_id      = var.vpc_id

  ingress {
    description = "Allow HTTPS from anywhere"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "Allow HTTP for redirect to HTTPS"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  egress {
    description = "Outbound to internal app compute"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name        = "omnipublish-${var.environment}-alb-sg"
    Environment = var.environment
  }

  lifecycle {
    create_before_destroy = true
  }
}

# 2. App Compute Security Group (ECS Fargate Tasks: API, Frontend, Workers, Scheduler)
resource "aws_security_group" "app_compute" {
  name_prefix = "omnipublish-${var.environment}-app-sg-"
  description = "Security group for ECS Fargate compute tasks"
  vpc_id      = var.vpc_id

  ingress {
    description     = "Allow HTTP traffic from ALB to API container"
    from_port       = 8000
    to_port         = 8000
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }

  ingress {
    description     = "Allow HTTP traffic from ALB to Next.js Frontend"
    from_port       = 3000
    to_port         = 3000
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }

  egress {
    description = "Allow all outbound traffic (Social APIs, S3, RDS, Redis via NAT/Endpoint)"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name        = "omnipublish-${var.environment}-app-compute-sg"
    Environment = var.environment
  }

  lifecycle {
    create_before_destroy = true
  }
}

# 3. Database Security Group (RDS PostgreSQL 16)
resource "aws_security_group" "database" {
  name_prefix = "omnipublish-${var.environment}-db-sg-"
  description = "Controls access to PostgreSQL database (strictly from app compute)"
  vpc_id      = var.vpc_id

  ingress {
    description     = "PostgreSQL access strictly from ECS app compute"
    from_port       = 5432
    to_port         = 5432
    protocol        = "tcp"
    security_groups = [aws_security_group.app_compute.id]
  }

  # Zero outbound rules needed for isolated relational store
  tags = {
    Name        = "omnipublish-${var.environment}-database-sg"
    Environment = var.environment
  }

  lifecycle {
    create_before_destroy = true
  }
}

# 4. Redis Security Group (ElastiCache Valkey / Redis 7)
resource "aws_security_group" "redis" {
  name_prefix = "omnipublish-${var.environment}-redis-sg-"
  description = "Controls access to Redis/Valkey cluster (strictly from app compute)"
  vpc_id      = var.vpc_id

  ingress {
    description     = "Redis access strictly from ECS app compute"
    from_port       = 6379
    to_port         = 6379
    protocol        = "tcp"
    security_groups = [aws_security_group.app_compute.id]
  }

  tags = {
    Name        = "omnipublish-${var.environment}-redis-sg"
    Environment = var.environment
  }

  lifecycle {
    create_before_destroy = true
  }
}

# Outputs
output "alb_security_group_id" {
  value = aws_security_group.alb.id
}

output "app_compute_security_group_id" {
  value = aws_security_group.app_compute.id
}

output "database_security_group_id" {
  value = aws_security_group.database.id
}

output "redis_security_group_id" {
  value = aws_security_group.redis.id
}
