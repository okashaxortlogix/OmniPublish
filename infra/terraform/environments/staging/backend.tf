# =========================================================================
# OmniPublish Staging — Remote Backend Configuration
# =========================================================================

terraform {
  required_version = ">= 1.8.0"

  # S3 Remote State Backend with DynamoDB State Locking
  # Run `terraform init` with backend config arguments or configure below when bucket is provisioned
  backend "s3" {
    bucket         = "omnipublish-staging-tfstate"
    key            = "staging/terraform.tfstate"
    region         = "us-east-1"
    encrypt        = true
    dynamodb_table = "omnipublish-staging-tflocks"
  }
}
