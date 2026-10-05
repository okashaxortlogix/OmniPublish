# =========================================================================
# OmniPublish ECS Module — Fargate Cluster, Tasks & Services
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
  description = "VPC ID"
}

variable "private_subnet_ids" {
  type        = list(string)
  description = "Private application subnet IDs"
}

variable "security_group_id" {
  type        = string
  description = "App compute security group ID"
}

variable "api_target_group_arn" {
  type        = string
  description = "ALB target group ARN for API container"
}

variable "frontend_target_group_arn" {
  type        = string
  description = "ALB target group ARN for Frontend container"
}

variable "s3_media_bucket_arn" {
  type        = string
  description = "S3 media bucket ARN"
}

variable "s3_media_bucket_name" {
  type        = string
  description = "S3 media bucket name"
}

variable "secrets_policy_arn" {
  type        = string
  description = "IAM policy ARN for reading secrets"
}

variable "app_key_secret_arn" {
  type        = string
  default     = null
  description = "Secrets Manager ARN for master application key (APP_KEY)"
}

variable "db_credentials_secret_arn" {
  type        = string
  default     = null
  description = "Secrets Manager ARN for PostgreSQL password (DB_PASSWORD)"
}

variable "redis_auth_secret_arn" {
  type        = string
  default     = null
  description = "Secrets Manager ARN for Redis AUTH token (REDIS_PASSWORD)"
}

variable "db_endpoint" {
  type        = string
  description = "RDS PostgreSQL endpoint"
}

variable "db_name" {
  type        = string
  description = "Database name"
}

variable "db_user" {
  type        = string
  description = "Database user"
}

variable "redis_endpoint" {
  type        = string
  description = "ElastiCache primary endpoint"
}

variable "image_tag" {
  type        = string
  default     = "staging-latest"
  description = "Immutable container image tag (Git SHA)"
}

variable "ecr_repository_urls" {
  type        = map(string)
  description = "Map of ECR repository URLs per service"
}

# 1. ECS Cluster
resource "aws_ecs_cluster" "main" {
  name = "omnipublish-${var.environment}-cluster"

  setting {
    name  = "containerInsights"
    value = var.environment == "production" ? "enabled" : "disabled"
  }

  tags = {
    Name        = "omnipublish-${var.environment}-cluster"
    Environment = var.environment
  }
}

# 2. IAM Roles
# Task Execution Role (used by ECS agent to pull image and fetch secrets)
resource "aws_iam_role" "execution" {
  name_prefix = "omnipublish-${var.environment}-exec-role-"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action    = "sts:AssumeRole"
        Effect    = "Allow"
        Principal = { Service = "ecs-tasks.amazonaws.com" }
      }
    ]
  })
}

resource "aws_iam_role_policy_attachment" "execution_standard" {
  role       = aws_iam_role.execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

resource "aws_iam_role_policy_attachment" "execution_secrets" {
  role       = aws_iam_role.execution.name
  policy_arn = var.secrets_policy_arn
}

# Task Role (used by the running application inside container: S3 read/write)
resource "aws_iam_role" "task" {
  name_prefix = "omnipublish-${var.environment}-task-role-"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action    = "sts:AssumeRole"
        Effect    = "Allow"
        Principal = { Service = "ecs-tasks.amazonaws.com" }
      }
    ]
  })
}

resource "aws_iam_policy" "s3_access" {
  name_prefix = "omnipublish-${var.environment}-s3-policy-"

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid    = "BucketLevelPermissions"
        Effect = "Allow"
        Action = [
          "s3:ListBucket",
          "s3:GetBucketLocation"
        ]
        Resource = [
          var.s3_media_bucket_arn
        ]
      },
      {
        Sid    = "ObjectLevelPermissions"
        Effect = "Allow"
        Action = [
          "s3:GetObject",
          "s3:PutObject",
          "s3:DeleteObject",
          "s3:AbortMultipartUpload"
        ]
        Resource = [
          "${var.s3_media_bucket_arn}/*"
        ]
      }
    ]
  })
}

resource "aws_iam_role_policy_attachment" "task_s3" {
  role       = aws_iam_role.task.name
  policy_arn = aws_iam_policy.s3_access.arn
}

# Helper locals for common backend environment variables and secrets
locals {
  common_backend_env = [
    { name = "APP_ENV", value = var.environment },
    { name = "APP_DEBUG", value = "false" },
    { name = "DB_CONNECTION", value = "pgsql" },
    { name = "DB_HOST", value = var.db_endpoint },
    { name = "DB_PORT", value = "5432" },
    { name = "DB_DATABASE", value = var.db_name },
    { name = "DB_USERNAME", value = var.db_user },
    { name = "REDIS_HOST", value = var.redis_endpoint },
    { name = "REDIS_PORT", value = "6379" },
    { name = "QUEUE_CONNECTION", value = "redis" },
    { name = "FILESYSTEM_DISK", value = "s3" },
    { name = "AWS_BUCKET", value = var.s3_media_bucket_name },
    { name = "AWS_DEFAULT_REGION", value = data.aws_region.current.name }
  ]

  common_backend_secrets = [
    for s in [
      var.app_key_secret_arn != null ? { name = "APP_KEY", valueFrom = var.app_key_secret_arn } : null,
      var.db_credentials_secret_arn != null ? { name = "DB_PASSWORD", valueFrom = var.db_credentials_secret_arn } : null,
      var.redis_auth_secret_arn != null ? { name = "REDIS_PASSWORD", valueFrom = var.redis_auth_secret_arn } : null,
    ] : s if s != null
  ]
}

data "aws_region" "current" {}

# =========================================================================
# Service 1: Laravel 12 API Service
# =========================================================================
resource "aws_ecs_task_definition" "api" {
  family                   = "omnipublish-${var.environment}-api"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "512"
  memory                   = "1024"
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  container_definitions = jsonencode([
    {
      name      = "api"
      image     = "${var.ecr_repository_urls["api"]}:${var.image_tag}"
      essential = true
      portMappings = [
        { containerPort = 8000, hostPort = 8000, protocol = "tcp" }
      ]
      environment = local.common_backend_env
      secrets     = length(local.common_backend_secrets) > 0 ? local.common_backend_secrets : null
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = "/ecs/omnipublish-${var.environment}-api"
          "awslogs-region"        = data.aws_region.current.name
          "awslogs-stream-prefix" = "api"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "api" {
  name            = "omnipublish-${var.environment}-api"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.api.arn
  desired_count   = var.environment == "production" ? 2 : 1
  launch_type     = "FARGATE"

  network_configuration {
    subnets         = var.private_subnet_ids
    security_groups = [var.security_group_id]
  }

  load_balancer {
    target_group_arn = var.api_target_group_arn
    container_name   = "api"
    container_port   = 8000
  }
}

# =========================================================================
# Service 2: Next.js Frontend Service
# =========================================================================
resource "aws_ecs_task_definition" "frontend" {
  family                   = "omnipublish-${var.environment}-frontend"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "512"
  memory                   = "1024"
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  container_definitions = jsonencode([
    {
      name      = "frontend"
      image     = "${var.ecr_repository_urls["frontend"]}:${var.image_tag}"
      essential = true
      portMappings = [
        { containerPort = 3000, hostPort = 3000, protocol = "tcp" }
      ]
      environment = [
        { name = "NODE_ENV", value = "production" },
        { name = "PORT", value = "3000" }
      ]
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = "/ecs/omnipublish-${var.environment}-frontend"
          "awslogs-region"        = data.aws_region.current.name
          "awslogs-stream-prefix" = "frontend"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "frontend" {
  name            = "omnipublish-${var.environment}-frontend"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.frontend.arn
  desired_count   = 1
  launch_type     = "FARGATE"

  network_configuration {
    subnets         = var.private_subnet_ids
    security_groups = [var.security_group_id]
  }

  load_balancer {
    target_group_arn = var.frontend_target_group_arn
    container_name   = "frontend"
    container_port   = 3000
  }
}

# =========================================================================
# Service 3: Media Processing Worker (FFmpeg / Scratch Disk)
# =========================================================================
resource "aws_ecs_task_definition" "media_worker" {
  family                   = "omnipublish-${var.environment}-media-worker"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "2048" # 2.0 vCPU
  memory                   = "4096" # 4.0 GB RAM
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  ephemeral_storage {
    size_in_gib = 30 # 30 GB NVMe scratch space for video streams
  }

  container_definitions = jsonencode([
    {
      name        = "media-worker"
      image       = "${var.ecr_repository_urls["media-worker"]}:${var.image_tag}"
      essential   = true
      stopTimeout = 120 # Graceful period for in-flight FFmpeg jobs
      environment = local.common_backend_env
      secrets     = length(local.common_backend_secrets) > 0 ? local.common_backend_secrets : null
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = "/ecs/omnipublish-${var.environment}-media-worker"
          "awslogs-region"        = data.aws_region.current.name
          "awslogs-stream-prefix" = "media"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "media_worker" {
  name            = "omnipublish-${var.environment}-media-worker"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.media_worker.arn
  desired_count   = 1
  launch_type     = "FARGATE"

  network_configuration {
    subnets         = var.private_subnet_ids
    security_groups = [var.security_group_id]
  }
}

# =========================================================================
# Service 4: Publishing Worker
# =========================================================================
resource "aws_ecs_task_definition" "publishing_worker" {
  family                   = "omnipublish-${var.environment}-publishing-worker"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "512"
  memory                   = "1024"
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  container_definitions = jsonencode([
    {
      name        = "publishing-worker"
      image       = "${var.ecr_repository_urls["publishing-worker"]}:${var.image_tag}"
      essential   = true
      stopTimeout = 120 # Graceful period for in-flight chunked uploads
      environment = local.common_backend_env
      secrets     = length(local.common_backend_secrets) > 0 ? local.common_backend_secrets : null
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = "/ecs/omnipublish-${var.environment}-publishing-worker"
          "awslogs-region"        = data.aws_region.current.name
          "awslogs-stream-prefix" = "publishing"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "publishing_worker" {
  name            = "omnipublish-${var.environment}-publishing-worker"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.publishing_worker.arn
  desired_count   = 1
  launch_type     = "FARGATE"

  network_configuration {
    subnets         = var.private_subnet_ids
    security_groups = [var.security_group_id]
  }
}

# =========================================================================
# Service 5: Default Worker (General Queues & Batch Coordination)
# =========================================================================
resource "aws_ecs_task_definition" "default_worker" {
  family                   = "omnipublish-${var.environment}-default-worker"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "256"
  memory                   = "512"
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  container_definitions = jsonencode([
    {
      name        = "default-worker"
      image       = "${var.ecr_repository_urls["default-worker"]}:${var.image_tag}"
      essential   = true
      stopTimeout = 60
      environment = local.common_backend_env
      secrets     = length(local.common_backend_secrets) > 0 ? local.common_backend_secrets : null
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = "/ecs/omnipublish-${var.environment}-default-worker"
          "awslogs-region"        = data.aws_region.current.name
          "awslogs-stream-prefix" = "default"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "default_worker" {
  name            = "omnipublish-${var.environment}-default-worker"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.default_worker.arn
  desired_count   = 1
  launch_type     = "FARGATE"

  network_configuration {
    subnets         = var.private_subnet_ids
    security_groups = [var.security_group_id]
  }
}

# =========================================================================
# Service 6: Scheduler Daemon
# =========================================================================
resource "aws_ecs_task_definition" "scheduler" {
  family                   = "omnipublish-${var.environment}-scheduler"
  network_mode             = "awsvpc"
  requires_compatibilities = ["FARGATE"]
  cpu                      = "256"
  memory                   = "512"
  execution_role_arn       = aws_iam_role.execution.arn
  task_role_arn            = aws_iam_role.task.arn

  container_definitions = jsonencode([
    {
      name        = "scheduler"
      image       = "${var.ecr_repository_urls["scheduler"]}:${var.image_tag}"
      essential   = true
      stopTimeout = 30
      environment = local.common_backend_env
      secrets     = length(local.common_backend_secrets) > 0 ? local.common_backend_secrets : null
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = "/ecs/omnipublish-${var.environment}-scheduler"
          "awslogs-region"        = data.aws_region.current.name
          "awslogs-stream-prefix" = "scheduler"
        }
      }
    }
  ])
}

resource "aws_ecs_service" "scheduler" {
  name            = "omnipublish-${var.environment}-scheduler"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.scheduler.arn
  desired_count   = 1
  launch_type     = "FARGATE"

  network_configuration {
    subnets         = var.private_subnet_ids
    security_groups = [var.security_group_id]
  }
}

# Outputs
output "cluster_name" {
  value = aws_ecs_cluster.main.name
}

output "cluster_arn" {
  value = aws_ecs_cluster.main.arn
}
