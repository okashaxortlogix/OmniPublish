# =========================================================================
# OmniPublish Secrets Module — AWS Secrets Manager & Access Policies
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

# 1. Master Application Key Shell (APP_KEY)
resource "aws_secretsmanager_secret" "app_key" {
  name_prefix             = "omnipublish/${var.environment}/APP_KEY-"
  description             = "Master AES-256 application encryption key"
  recovery_window_in_days = var.environment == "production" ? 30 : 0
}

# 2. Database Credentials Secret Shell
resource "aws_secretsmanager_secret" "db_credentials" {
  name_prefix             = "omnipublish/${var.environment}/DB_CREDENTIALS-"
  description             = "PostgreSQL database credentials"
  recovery_window_in_days = var.environment == "production" ? 30 : 0
}

# 3. Redis / Valkey Auth Token Shell
resource "aws_secretsmanager_secret" "redis_auth" {
  name_prefix             = "omnipublish/${var.environment}/REDIS_AUTH-"
  description             = "Redis/Valkey cluster authentication token"
  recovery_window_in_days = var.environment == "production" ? 30 : 0
}

# 4. Social OAuth Credentials Shell (Google, TikTok, Meta)
resource "aws_secretsmanager_secret" "social_oauth" {
  name_prefix             = "omnipublish/${var.environment}/SOCIAL_OAUTH-"
  description             = "Third-party platform OAuth client keys and secrets"
  recovery_window_in_days = var.environment == "production" ? 30 : 0
}

# 5. IAM Policy for ECS Tasks to Read Secrets
data "aws_iam_policy_document" "read_secrets" {
  statement {
    effect = "Allow"
    actions = [
      "secretsmanager:GetSecretValue",
      "secretsmanager:DescribeSecret"
    ]
    resources = [
      aws_secretsmanager_secret.app_key.arn,
      aws_secretsmanager_secret.db_credentials.arn,
      aws_secretsmanager_secret.redis_auth.arn,
      aws_secretsmanager_secret.social_oauth.arn
    ]
  }
}

resource "aws_iam_policy" "read_secrets" {
  name_prefix = "omnipublish-${var.environment}-secrets-policy-"
  description = "Allows ECS tasks to decrypt and read application secrets"
  policy      = data.aws_iam_policy_document.read_secrets.json
}

# Outputs
output "app_key_secret_arn" {
  value = aws_secretsmanager_secret.app_key.arn
}

output "db_credentials_secret_arn" {
  value = aws_secretsmanager_secret.db_credentials.arn
}

output "redis_auth_secret_arn" {
  value = aws_secretsmanager_secret.redis_auth.arn
}

output "social_oauth_secret_arn" {
  value = aws_secretsmanager_secret.social_oauth.arn
}

output "secrets_read_policy_arn" {
  value = aws_iam_policy.read_secrets.arn
}
