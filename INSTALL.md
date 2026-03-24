# PQCServer — Installation Guide

## Requirements

- Ubuntu 22.04 / 24.04 LTS
- PHP 8.3 with MongoDB extension
- MongoDB 7.0
- Nginx
- Composer
- Python 3 (for cleanup script)

---

## Automated Install

```bash
git clone https://github.com/onionsearchengine/pqcserver.git
cd pqcserver
chmod +x install.sh
sudo bash install.sh
```

The script installs all dependencies, deploys files, creates
MongoDB indexes, and configures Nginx automatically.

---

## Manual Step-by-Step

### 1. System packages
```bash
apt-get update && apt-get upgrade -y
apt-get install -y nginx php8.3-fpm php8.3-cli php8.3-curl \
  php8.3-mbstring php8.3-zip unzip curl git python3 python3-pip
```

### 2. PHP MongoDB extension
```bash
apt-get install -y php-pear php8.3-dev libssl-dev pkg-config
pecl install mongodb
echo "extension=mongodb.so" >> /etc/php/8.3/fpm/php.ini
echo "extension=mongodb.so" >> /etc/php/8.3/cli/php.ini
```

### 3. MongoDB 7.0
```bash
curl -fsSL https://www.mongodb.org/static/pgp/server-7.0.asc \
  | gpg -o /usr/share/keyrings/mongodb-server-7.0.gpg --dearmor

echo "deb [ arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-7.0.gpg ] \
  https://repo.mongodb.org/apt/ubuntu jammy/mongodb-org/7.0 multiverse" \
  | tee /etc/apt/sources.list.d/mongodb-org-7.0.list

apt-get update && apt-get install -y mongodb-org
systemctl enable mongod && systemctl start mongod
```

### 4. Composer + PHP dependencies
```bash
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
cd /var/www/pqcserver
composer install --no-dev --optimize-autoloader
```

### 5. Deploy files
```bash
mkdir -p /var/www/pqcserver
cp -r . /var/www/pqcserver/
chown -R www-data:www-data /var/www/pqcserver
chmod -R 755 /var/www/pqcserver
chmod -R 750 /var/www/pqcserver/api \
             /var/www/pqcserver/config \
             /var/www/pqcserver/scripts
```

### 6. Environment configuration
```bash
cp .env.example .env
nano .env
# Set BASE_URL, MONGO_URI, and other variables
```

### 7. Generate server signing keys (for Notary)
```bash
php scripts/generate_server_keys.php
# Follow the output instructions to set environment variables
```

### 8. MongoDB indexes
```bash
mongosh pqcserver mongo_indexes.js
```

### 9. Nginx configuration
```bash
cp nginx.conf /etc/nginx/sites-available/pqcserver.com
# Edit the domain name if needed:
# nano /etc/nginx/sites-available/pqcserver.com

ln -sf /etc/nginx/sites-available/pqcserver.com \
       /etc/nginx/sites-enabled/pqcserver.com
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

### 10. PHP-FPM restart
```bash
systemctl restart php8.3-fpm
systemctl restart nginx
```

### 11. Cron job (cleanup + stats)
```bash
crontab -e
# Add this line:
0 3 * * * python3 /var/www/pqcserver/scripts/cleanup.py >> /var/log/pqcserver.log 2>&1
```

---

## Cloudflare Setup

1. Add domain to Cloudflare
2. DNS A record → your server IP (Proxied ☁️)
3. SSL/TLS mode → **Flexible**
4. Enable "Always Use HTTPS"

---

## Verify Installation

```bash
# Test API
curl https://pqcserver.com/api/session.php
# Expected: {"ok":false,"user":null}

# Test MongoDB
mongosh pqcserver --eval "db.users.countDocuments({})"

# Test Nginx
nginx -t

# Test PHP
php -m | grep mongodb
```

---

## Troubleshooting

**MongoDB connection error**
```bash
systemctl status mongod
mongosh --eval "db.runCommand({ping:1})"
```

**PHP MongoDB extension not found**
```bash
php -m | grep mongodb
# If missing:
pecl install mongodb
systemctl restart php8.3-fpm
```

**Nginx 502 Bad Gateway**
```bash
systemctl status php8.3-fpm
ls /run/php/  # verify socket exists
tail -f /var/log/nginx/error.log
```

**Notary signing fails**
```bash
# Check server keys are configured
php -r "require 'config/server_keys.php'; var_dump(SERVER_KEYS_CONFIGURED);"
# If false, run:
php scripts/generate_server_keys.php
```
