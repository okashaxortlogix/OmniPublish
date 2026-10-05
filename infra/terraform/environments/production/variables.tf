# =========================================================================
# OmniPublish Production — Variables
# =========================================================================

variable "aws_region" {
  type        = string
  default     = "us-east-1"
  description = "AWS deployment region"
}

variable "environment" {
  type        = string
  default     = "production"
  description = "Deployment environment name"
}

variable "vpc_cidr" {
  type        = string
  default     = "10.0.0.0/16"
  description = "VPC CIDR block"
}

variable "availability_zones" {
  type        = list(string)
  default     = ["us-east-1a", "us-east-1b"]
  description = "Availability zones for production subnets"
}

variable "db_password" {
  type        = string
  sensitive   = true
  description = "Master password for production PostgreSQL database"
}

variable "redis_auth_token" {
  type        = string
  sensitive   = true
  description = "AUTH token for production Redis/Valkey cluster"
}

variable "image_tag" {
  type        = string
  description = "Immutable Git commit SHA release tag to deploy"
}

variable "certificate_arn" {
  type        = string
  default     = null
  description = "ACM TLS Certificate ARN for HTTPS listener"
}
