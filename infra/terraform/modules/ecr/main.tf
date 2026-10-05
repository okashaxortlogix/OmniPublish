# =========================================================================
# OmniPublish ECR Module — Immutable Container Repositories
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

locals {
  services = [
    "api",
    "publishing-worker",
    "media-worker",
    "default-worker",
    "scheduler",
    "frontend"
  ]
}

resource "aws_ecr_repository" "services" {
  for_each             = toset(local.services)
  name                 = "omnipublish-${var.environment}-${each.key}"
  image_tag_mutability = "IMMUTABLE" # Enforces unique Git SHA tags; blocks mutable tag overwrites

  image_scanning_configuration {
    scan_on_push = true # Automated vulnerability scanning
  }

  encryption_configuration {
    encryption_type = "AES256"
  }

  tags = {
    Name        = "omnipublish-${var.environment}-${each.key}"
    Environment = var.environment
    Service     = each.key
  }
}

# Lifecycle Policy: Retain last 30 untagged/tagged release images; purge older releases
resource "aws_ecr_lifecycle_policy" "services" {
  for_each   = aws_ecr_repository.services
  repository = each.value.name

  policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "Expire untagged images older than 7 days"
        selection = {
          tagStatus   = "untagged"
          countType   = "sinceImagePushed"
          countUnit   = "days"
          countNumber = 7
        }
        action = {
          type = "expire"
        }
      },
      {
        rulePriority = 2
        description  = "Keep last 30 tagged release images"
        selection = {
          tagStatus     = "tagged"
          tagPrefixList = ["v", "sha-", "commit-"]
          countType     = "imageCountMoreThan"
          countNumber   = 30
        }
        action = {
          type = "expire"
        }
      }
    ]
  })
}

# Outputs
output "repository_urls" {
  value = { for k, v in aws_ecr_repository.services : k => v.repository_url }
}
