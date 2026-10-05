# =========================================================================
# OmniPublish Staging — Variables
# =========================================================================

variable "aws_region" {
  type        = string
  default     = "us-east-1"
  description = "AWS deployment region"
}

variable "environment" {
  type        = string
  default     = "staging"
  description = "Deployment environment name"
}

variable "vpc_cidr" {
  type        = string
  default     = "10.1.0.0/16"
  description = "VPC CIDR block (isolated from production 10.0.0.0/16)"
}

variable "availability_zones" {
  type        = list(string)
  default     = ["us-east-1a", "us-east-1b"]
  description = "Availability zones for staging subnets"
}

variable "db_password" {
  type        = string
  sensitive   = true
  description = "Master password for staging PostgreSQL database"
}

variable "redis_auth_token" {
  type        = string
  sensitive   = true
  description = "AUTH token for staging Redis/Valkey cluster"
}

variable "image_tag" {
  type        = string
  default     = "staging-latest"
  description = "Git commit SHA or image tag to deploy"
}
