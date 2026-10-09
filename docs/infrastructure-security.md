# EUISIS Infrastructure Security Guide

**Project:** EUISIS — Addis Ababa City Administration Employee Unified ID & Service Integration System  
**Last updated:** 2026-05-25

---

## 1. HTTPS / TLS Requirement

All production traffic must be served over HTTPS. HTTP must redirect to HTTPS with a permanent (301) redirect.

### Minimum TLS Requirements

| Requirement | Value |
|---|---|
| Minimum TLS version | TLS 1.2 (TLS 1.3 preferred) |
| Allowed cipher suites | ECDHE-RSA-AES256-GCM-SHA384, ECDHE-RSA-AES128-GCM-SHA256, TLS_AES_256_GCM_SHA384 (TLS 1.3) |
| Certificate authority | Trusted public CA (Let's Encrypt or government PKI) |
| Certificate renewal | Automated via Certbot/ACME or at least 30 days before expiry |
| HSTS | `Strict-Transport-Security: max-age=31536000; includeSubDomains` — add to `SecurityHeaders` middleware once HTTPS is confirmed |

### Nginx TLS Configuration Snippet

```nginx
server {
    listen 443 ssl http2;
    server_name ems.pshrdb.gov.et;

    ssl_certificate     /etc/letsencrypt/live/ems.pshrdb.gov.et/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ems.pshrdb.gov.et/privkey.pem;

    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-GCM-SHA256:TLS_AES_256_GCM_SHA384;
    ssl_prefer_server_ciphers on;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 10m;

    # Hide version
    server_tokens off;

    # HSTS (enable only after confirming HTTPS works)
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Mobile users must not download React bundles uncompressed.
    # Keep this in the nginx http/server context, or enable the equivalent
    # Plesk "gzip compression" setting for this domain.
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/css application/javascript application/json application/wasm image/svg+xml;

    root /var/www/euisis/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Block access to hidden files (.env, .git, etc.)
    location ~ /\. {
        deny all;
        return 404;
    }

    # Block access to storage and bootstrap/cache directly
    location ~* ^/(storage|bootstrap/cache) {
        deny all;
        return 404;
    }
}

# Redirect all HTTP to HTTPS
server {
    listen 80;
    server_name ems.pshrdb.gov.et;
    return 301 https://$host$request_uri;
}
```

---

## 2. PHP Hardening

Add the following to `php.ini` on the production server:

```ini
; Disable PHP version disclosure in HTTP headers
expose_php = Off

; Disable dangerous functions
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,parse_ini_file,show_source

; File upload limits
file_uploads = On
upload_max_filesize = 5M
max_file_uploads = 5

; Error handling
display_errors = Off
log_errors = On
error_log = /var/log/php/error.log
```

---

## 3. Firewall Rules

Only the following ports should be open on the production server:

| Port | Protocol | Source | Purpose |
|---|---|---|---|
| 80 | TCP | Any | HTTP — redirect to HTTPS only |
| 443 | TCP | Any | HTTPS — application traffic |
| 22 | TCP | VPN / bastion IP only | SSH administration |
| 5432 | TCP | App server IP only | PostgreSQL — never open to internet |
| 6379 | TCP | App server IP only | Redis — never open to internet |

### UFW Example

```bash
# Default deny
ufw default deny incoming
ufw default allow outgoing

# Allow HTTPS and HTTP (redirect)
ufw allow 80/tcp
ufw allow 443/tcp

# Allow SSH from VPN/bastion only
ufw allow from 10.x.x.x to any port 22

# Enable
ufw enable
```

---

## 4. Database Security

- **PostgreSQL must not be exposed to the public internet.** Bind to `localhost` or the private network interface only (`listen_addresses = 'localhost'` or the app server's private IP in `postgresql.conf`).
- The application database user must have the minimum required privileges:

```sql
-- Create a restricted application user
CREATE USER euisis_app WITH PASSWORD 'strong_random_password';
GRANT CONNECT ON DATABASE euisis TO euisis_app;
GRANT USAGE ON SCHEMA public TO euisis_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO euisis_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO euisis_app;

-- Deny DDL operations
REVOKE CREATE ON SCHEMA public FROM euisis_app;
```

- Enable `ssl = on` in `postgresql.conf` and require SSL for the application user.
- Enable `log_connections = on` and `log_disconnections = on` in `postgresql.conf` for security monitoring.

---

## 5. Redis Security

If Redis is used for caching or queues:

- Bind to `127.0.0.1` only: `bind 127.0.0.1` in `redis.conf`.
- Set a strong `requirepass` password in `redis.conf`.
- Disable dangerous commands: `rename-command FLUSHALL ""` and `rename-command CONFIG ""`.
- Redis must not be accessible from outside the server.

---

## 6. VPN / Admin Access

- All SSH access must go through a VPN or a dedicated bastion host.
- Direct SSH from public IP addresses is prohibited.
- SSH must use key-based authentication only; password authentication must be disabled (`PasswordAuthentication no` in `/etc/ssh/sshd_config`).
- All admin accounts must use individual SSH key pairs (no shared keys).
- Key rotation: SSH keys rotated when a team member leaves.

---

## 7. Load Balancer Notes

If a load balancer (Nginx upstream, HAProxy, or a cloud LB) is deployed in front of the application:

- TLS termination can happen at the load balancer; use encrypted internal traffic (TLS 1.2+) between the LB and app servers.
- Pass the real client IP via `X-Forwarded-For` header and configure Laravel's trusted proxies (`APP_TRUSTED_PROXIES` or `config/trustedproxy.php`).
- The load balancer should perform health checks against `GET /up` (Laravel's built-in health endpoint, configured in `bootstrap/app.php`).

---

## 8. DDoS Protection

- Configure rate limiting at the Nginx level for the login endpoint:

```nginx
limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;

location /login {
    limit_req zone=login burst=10 nodelay;
    # ... rest of PHP handling
}
```

- For production deployments serving the public internet, consider a CDN/WAF layer (Cloudflare, AWS WAF, or equivalent) in front of Nginx.
- Laravel's application-level rate limiting (login throttle, API throttle) provides a second layer of defence.

---

## 9. Incident Response Contacts

| Role | Contact | Escalation Level |
|---|---|---|
| Primary DevOps | [Fill in] | First response |
| Security Lead | [Fill in] | Second escalation |
| Project Manager | [Fill in] | Third escalation |
| Data Protection Officer | [Fill in] | For PII breach notifications |
| Addis Ababa IT Authority | [Fill in] | Regulatory notification |

### Incident Severity Levels

| Severity | Description | Response Time |
|---|---|---|
| P1 — Critical | Production down, data breach, active intrusion | 30 minutes |
| P2 — High | Authentication failure, data corruption, audit log gap | 2 hours |
| P3 — Medium | Performance degradation, non-critical service failure | 8 hours |
| P4 — Low | Configuration drift, certificate near expiry | 24 hours |

### Breach Notification

If a personal data breach is suspected (national IDs, employee records), the Data Protection Officer must be notified within 1 hour. Regulatory notification to the relevant Ethiopian authority must follow within 72 hours per applicable data protection law.
