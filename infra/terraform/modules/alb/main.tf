# =========================================================================
# OmniPublish Ingress Module — Application Load Balancer (ALB)
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

variable "public_subnet_ids" {
  type        = list(string)
  description = "Public subnet IDs where ALB will listen"
}

variable "security_group_id" {
  type        = string
  description = "ALB security group ID"
}

variable "certificate_arn" {
  type        = string
  default     = null
  description = "ACM TLS Certificate ARN for HTTPS listener (if null, HTTP listener is used)"
}

# 1. Application Load Balancer
resource "aws_lb" "main" {
  name_prefix        = "omni-"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [var.security_group_id]
  subnets            = var.public_subnet_ids

  enable_deletion_protection = var.environment == "production" ? true : false
  drop_invalid_header_fields = true

  tags = {
    Name        = "omnipublish-${var.environment}-alb"
    Environment = var.environment
  }
}

# 2. Target Group: Laravel 12 API (Port 8000)
resource "aws_lb_target_group" "api" {
  name_prefix          = "api-"
  port                 = 8000
  protocol             = "HTTP"
  vpc_id               = var.vpc_id
  target_type          = "ip" # ECS Fargate uses 'ip' target type
  deregistration_delay = 30

  health_check {
    enabled             = true
    path                = "/health/live"
    port                = "8000"
    protocol            = "HTTP"
    matcher             = "200"
    interval            = 15
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = {
    Name        = "omnipublish-${var.environment}-tg-api"
    Environment = var.environment
  }
}

# 3. Target Group: Next.js Frontend (Port 3000)
resource "aws_lb_target_group" "frontend" {
  name_prefix          = "web-"
  port                 = 3000
  protocol             = "HTTP"
  vpc_id               = var.vpc_id
  target_type          = "ip"
  deregistration_delay = 30

  health_check {
    enabled             = true
    path                = "/"
    port                = "3000"
    protocol            = "HTTP"
    matcher             = "200-399"
    interval            = 15
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = {
    Name        = "omnipublish-${var.environment}-tg-frontend"
    Environment = var.environment
  }
}

# 4. HTTP Listener (Port 80)
resource "aws_lb_listener" "http" {
  load_balancer_arn = aws_lb.main.arn
  port              = 80
  protocol          = "HTTP"

  # If HTTPS certificate exists, redirect HTTP to HTTPS; otherwise forward directly
  default_action {
    type             = var.certificate_arn != null ? "redirect" : "forward"
    target_group_arn = var.certificate_arn == null ? aws_lb_target_group.frontend.arn : null

    dynamic "redirect" {
      for_each = var.certificate_arn != null ? [1] : []
      content {
        port        = "443"
        protocol    = "HTTPS"
        status_code = "HTTP_301"
      }
    }
  }
}

# 5. HTTPS Listener (Port 443, when certificate is provided)
resource "aws_lb_listener" "https" {
  count             = var.certificate_arn != null ? 1 : 0
  load_balancer_arn = aws_lb.main.arn
  port              = 443
  protocol          = "HTTPS"
  ssl_policy        = "ELBSecurityPolicy-TLS13-1-2-2021-06"
  certificate_arn   = var.certificate_arn

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.frontend.arn
  }
}

# 6. Routing Rules: Forward /api/* and /health* to Laravel API
resource "aws_lb_listener_rule" "api_routing" {
  listener_arn = var.certificate_arn != null ? aws_lb_listener.https[0].arn : aws_lb_listener.http.arn
  priority     = 10

  action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.api.arn
  }

  condition {
    path_pattern {
      values = ["/api/*", "/health*", "/version*"]
    }
  }
}

# Outputs
output "alb_arn" {
  value = aws_lb.main.arn
}

output "dns_name" {
  value = aws_lb.main.dns_name
}

output "zone_id" {
  value = aws_lb.main.zone_id
}

output "api_target_group_arn" {
  value = aws_lb_target_group.api.arn
}

output "frontend_target_group_arn" {
  value = aws_lb_target_group.frontend.arn
}

output "arn_suffix" {
  value = aws_lb.main.arn_suffix
}
