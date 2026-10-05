# =========================================================================
# OmniPublish Monitoring Module — CloudWatch Log Groups & Alarms
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

variable "retention_in_days" {
  type        = number
  default     = 30
  description = "CloudWatch log retention period in days"
}

variable "alb_arn_suffix" {
  type        = string
  default     = null
  description = "ALB ARN suffix for CloudWatch metrics (e.g. app/omni-xxx/yyy)"
}

variable "db_instance_identifier" {
  type        = string
  default     = null
  description = "RDS DB instance identifier for CloudWatch metrics"
}

locals {
  services = [
    "api",
    "frontend",
    "publishing-worker",
    "media-worker",
    "default-worker",
    "scheduler"
  ]
}

# 1. Dedicated CloudWatch Log Groups per Service
resource "aws_cloudwatch_log_group" "services" {
  for_each          = toset(local.services)
  name              = "/ecs/omnipublish-${var.environment}-${each.key}"
  retention_in_days = var.retention_in_days

  tags = {
    Name        = "omnipublish-${var.environment}-${each.key}-logs"
    Environment = var.environment
    Service     = each.key
  }
}

# 2. Metric Filters: Capture Laravel Error Logs
resource "aws_cloudwatch_log_metric_filter" "laravel_errors" {
  name           = "omnipublish-${var.environment}-laravel-errors"
  pattern        = "[level = ERROR || level = CRITICAL]"
  log_group_name = aws_cloudwatch_log_group.services["api"].name

  metric_transformation {
    name      = "LaravelErrorCount"
    namespace = "OmniPublish/${var.environment}"
    value     = "1"
  }
}

# 3. CloudWatch Alarm: High API Error Rate
resource "aws_cloudwatch_metric_alarm" "api_errors" {
  alarm_name          = "omnipublish-${var.environment}-api-errors-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  metric_name         = "LaravelErrorCount"
  namespace           = "OmniPublish/${var.environment}"
  period              = 60
  statistic           = "Sum"
  threshold           = 10
  alarm_description   = "Fires when API logs exceed 10 errors per minute"

  tags = {
    Environment = var.environment
  }
}

# 4. CloudWatch Alarm: ALB 5XX Errors
resource "aws_cloudwatch_metric_alarm" "alb_5xx" {
  count               = var.alb_arn_suffix != null ? 1 : 0
  alarm_name          = "omnipublish-${var.environment}-alb-5xx-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  metric_name         = "HTTPCode_Target_5XX_Count"
  namespace           = "AWS/ApplicationELB"
  period              = 60
  statistic           = "Sum"
  threshold           = 5
  alarm_description   = "Fires when ALB target 5XX errors exceed 5 within 2 evaluation periods"

  dimensions = {
    LoadBalancer = var.alb_arn_suffix
  }

  tags = {
    Environment = var.environment
  }
}

# 5. CloudWatch Alarm: RDS High CPU Utilization
resource "aws_cloudwatch_metric_alarm" "rds_high_cpu" {
  count               = var.db_instance_identifier != null ? 1 : 0
  alarm_name          = "omnipublish-${var.environment}-rds-high-cpu"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  metric_name         = "CPUUtilization"
  namespace           = "AWS/RDS"
  period              = 300
  statistic           = "Average"
  threshold           = 80
  alarm_description   = "Fires when RDS CPU utilization exceeds 80% for 15 consecutive minutes"

  dimensions = {
    DBInstanceIdentifier = var.db_instance_identifier
  }

  tags = {
    Environment = var.environment
  }
}

# 6. CloudWatch Alarm: RDS Low Free Storage Space (< 5 GB)
resource "aws_cloudwatch_metric_alarm" "rds_low_storage" {
  count               = var.db_instance_identifier != null ? 1 : 0
  alarm_name          = "omnipublish-${var.environment}-rds-low-storage"
  comparison_operator = "LessThanOrEqualToThreshold"
  evaluation_periods  = 1
  metric_name         = "FreeStorageSpace"
  namespace           = "AWS/RDS"
  period              = 300
  statistic           = "Average"
  threshold           = 5000000000 # 5 GB in bytes
  alarm_description   = "Fires when RDS free storage space falls below 5 GB"

  dimensions = {
    DBInstanceIdentifier = var.db_instance_identifier
  }

  tags = {
    Environment = var.environment
  }
}

# Outputs
output "log_group_names" {
  value = { for k, v in aws_cloudwatch_log_group.services : k => v.name }
}

output "log_group_arns" {
  value = { for k, v in aws_cloudwatch_log_group.services : k => v.arn }
}
