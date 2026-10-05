# =========================================================================
# OmniPublish Production — Remote Backend Configuration
# =========================================================================

terraform {
  required_version = ">= 1.8.0"

  # S3 Remote State Backend with DynamoDB State Locking (Separate Production State)
  backend "s3" {
    bucket         = "omnipublish-production-tfstate"
    key            = "production/terraform.tfstate"
    region         = "us-east-1"
    encrypt        = true
    dynamodb_table = "omnipublish-production-tflocks"
  }
}
