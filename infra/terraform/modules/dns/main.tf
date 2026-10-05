# =========================================================================
# OmniPublish DNS & TLS Module — Route 53 & ACM Certificates
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

variable "domain_name" {
  type        = string
  description = "Apex or base domain name (e.g., omnipublish.com)"
}

variable "subdomains" {
  type        = list(string)
  default     = ["app", "api", "staging"]
  description = "List of subdomains to generate certificates and DNS records for"
}

variable "alb_dns_name" {
  type        = string
  description = "Target Application Load Balancer DNS name"
}

variable "alb_zone_id" {
  type        = string
  description = "Target Application Load Balancer Route 53 Zone ID"
}

# 1. Lookup or Manage Hosted Zone
data "aws_route53_zone" "primary" {
  name         = var.domain_name
  private_zone = false
}

# 2. ACM TLS Certificate
resource "aws_acm_certificate" "cert" {
  domain_name               = var.domain_name
  subject_alternative_names = [for sub in var.subdomains : "${sub}.${var.domain_name}"]
  validation_method         = "DNS"

  lifecycle {
    create_before_destroy = true
  }

  tags = {
    Name = "omnipublish-acm-cert"
  }
}

# 3. DNS Validation Records in Route 53
resource "aws_route53_record" "cert_validation" {
  for_each = {
    for dvo in aws_acm_certificate.cert.domain_validation_options : dvo.domain_name => {
      name   = dvo.resource_record_name
      record = dvo.resource_record_value
      type   = dvo.resource_record_type
    }
  }

  allow_overwrite = true
  name            = each.value.name
  records         = [each.value.record]
  ttl             = 60
  type            = each.value.type
  zone_id         = data.aws_route53_zone.primary.zone_id
}

# 4. Certificate Validation Waiter
resource "aws_acm_certificate_validation" "cert" {
  certificate_arn         = aws_acm_certificate.cert.arn
  validation_record_fqdns = [for record in aws_route53_record.cert_validation : record.fqdn]
}

# 5. Route 53 Alias Records Pointing to ALB
resource "aws_route53_record" "subdomains" {
  for_each = toset(var.subdomains)

  zone_id = data.aws_route53_zone.primary.zone_id
  name    = "${each.key}.${var.domain_name}"
  type    = "A"

  alias {
    name                   = var.alb_dns_name
    zone_id                = var.alb_zone_id
    evaluate_target_health = true
  }
}

# Outputs
output "certificate_arn" {
  value = aws_acm_certificate.cert.arn
}

output "zone_id" {
  value = data.aws_route53_zone.primary.zone_id
}
