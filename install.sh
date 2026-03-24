#!/bin/bash
# PQCServer — Automated Installation Script
# Ubuntu 22.04 / 24.04 LTS
# Run as root: bash install.sh

set -e

DOMAIN="pqcserver.com"
WEBROOT="/var/www/pqcserver"
PHP_SOCK="/run/php/php8.3-fpm.sock"

echo "=============================================="
echo "  PQCServer Installation"
echo "  Domain: $DOMAIN"
echo "=============================================="
echo ""

# ── 1. System update ──────────────────────────────
echo "[1/8] Updating system packages..."
apt-get update -qq
apt-get upgrade -y -qq

# ── 2. Nginx + PHP ────────────────────────────────
echo "[2/8] Installing Nginx + PHP 8.3..."
apt-get install -y -qq nginx php8.3-fpm php8.3-cli php8.3-curl php8.3-mbstring php8.3-zip unzip curl git

# ── 3. PHP MongoDB extension ──────────────────────
echo "[3/8] Installing PHP MongoDB extension..."
apt-get install -y -qq php-pear php8.3-dev libssl-dev pkg-config
pecl channel-update pecl.php.net
pecl install mongodb || true
# Add extension to php.ini if not present
PHP_INI="/etc/php/8.3/fpm/php.ini"
PHP_INI_CLI="/etc/php/8.3/cli/php.ini"
grep -q "extension=mongodb" "$PHP_INI" || echo "extension=mongodb.so" >> "$PHP_INI"
grep -q "extension=mongodb" "$PHP_INI_CLI" || echo "extension=mongodb.so" >> "$PHP_INI_CLI"

# ── 4. MongoDB ────────────────────────────────────
echo "[4/8] Installing MongoDB..."
curl -fsSL https://www.mongodb.org/static/pgp/server-7.0.asc | gpg -o /usr/share/keyrings/mongodb-server-7.0.gpg --dearmor
echo "deb [ arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-7.0.gpg ] https://repo.mongodb.org/apt/ubuntu $(lsb_release -cs)/mongodb-org/7.0 multiverse" \
  | tee /etc/apt/sources.list.d/mongodb-org-7.0.list
apt-get update -qq
apt-get install -y -qq mongodb-org
systemctl enable mongod
systemctl start mongod
echo "  MongoDB started."

# ── 5. Composer ───────────────────────────────────
echo "[5/8] Installing Composer..."
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer
chmod +x /usr/local/bin/composer

# ── 6. Deploy project files ───────────────────────
echo "[6/8] Deploying project files..."
mkdir -p "$WEBROOT"
cp -r . "$WEBROOT/"
chown -R www-data:www-data "$WEBROOT"
chmod -R 755 "$WEBROOT"
chmod -R 750 "$WEBROOT/api" "$WEBROOT/config" "$WEBROOT/scripts"

# Install PHP dependencies
cd "$WEBROOT"
composer install --no-dev --optimize-autoloader

# ── 7. MongoDB indexes ────────────────────────────
echo "[7/8] Creating MongoDB indexes..."
mongosh pqcserver --eval "
  db.messages.createIndex({ 'expires_at': 1 }, { expireAfterSeconds: 0, name: 'ttl_expires' });
  db.messages.createIndex({ 'recipient': 1 }, { sparse: true, name: 'idx_recipient' });
  db.users.createIndex({ 'email': 1 }, { sparse: true, name: 'idx_email' });
  print('Indexes created OK');
"

# ── 8. Nginx config ───────────────────────────────
echo "[8/8] Configuring Nginx..."
cp "$WEBROOT/nginx.conf" "/etc/nginx/sites-available/$DOMAIN"
ln -sf "/etc/nginx/sites-available/$DOMAIN" "/etc/nginx/sites-enabled/$DOMAIN"
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

# ── Cron for cleanup ──────────────────────────────
echo "  Setting up cron job..."
(crontab -l 2>/dev/null; echo "0 3 * * * python3 $WEBROOT/scripts/cleanup.py >> /var/log/pqcserver-cleanup.log 2>&1") | crontab -

# ── PHP session config ────────────────────────────────
echo "  Configuring PHP sessions..."
mkdir -p /var/lib/pqcserver_sessions
chown www-data:www-data /var/lib/pqcserver_sessions
chmod 700 /var/lib/pqcserver_sessions
PHP_FPM_POOL="/etc/php/8.3/fpm/pool.d/www.conf"
grep -q "session.save_path" "$PHP_FPM_POOL" || echo "
php_value[session.save_path] = /var/lib/pqcserver_sessions
php_value[session.gc_maxlifetime] = 2592000
php_value[session.cookie_secure] = 1
php_value[session.cookie_httponly] = 1
php_value[session.use_strict_mode] = 1" >> "$PHP_FPM_POOL"

# ── PHP-FPM restart ───────────────────────────────
systemctl restart php8.3-fpm
systemctl restart nginx

echo ""
echo "=============================================="
echo "  Installation complete!"
echo ""
echo "  Site:    http://$DOMAIN"
echo "  Webroot: $WEBROOT"
echo "  Logs:    /var/log/nginx/error.log"
echo ""
echo "  Next steps:"
echo "  1. Point DNS A record to this server IP"
echo "  2. Set Cloudflare to Flexible SSL mode"
echo "  3. Edit $WEBROOT/config/db.php if needed"
echo "  4. Test: curl http://$DOMAIN/api/pubkey.php?u=test"
echo "=============================================="
