#!/usr/bin/env bash
# Install PHP 8.3 + Swoole inside WSL (Ubuntu 24.04).
# Run from inside WSL:  bash scripts/install-swoole-wsl.sh
# Requires sudo (you will be prompted for your password).
set -euo pipefail

echo "==> Updating apt package lists"
sudo apt-get update -y

echo "==> Installing PHP 8.3 CLI + build toolchain for PECL"
sudo apt-get install -y \
  php8.3-cli php8.3-dev php-pear \
  gcc make autoconf pkg-config \
  libssl-dev libcurl4-openssl-dev libc-ares-dev \
  libbrotli-dev

PHP_INI_DIR="$(php --ini | awk -F': ' '/Scan for additional/ {print $2}')"
echo "==> PHP conf.d dir: ${PHP_INI_DIR}"

if php -m | grep -qi '^swoole$'; then
  echo "==> Swoole already installed:"
  php --ri swoole | head -n 5
  exit 0
fi

echo "==> Trying apt package php8.3-swoole (fast path)"
if sudo apt-get install -y php8.3-swoole 2>/dev/null; then
  echo "==> Installed via apt"
else
  echo "==> apt package unavailable; building current Swoole via PECL"
  # Non-interactive PECL: enable common features, skip prompts.
  printf 'yes\nyes\nyes\nyes\nno\n' | sudo pecl install -f swoole
  # Enable the extension for the CLI SAPI.
  echo "extension=swoole.so" | sudo tee "${PHP_INI_DIR}/20-swoole.ini" >/dev/null
fi

echo "==> Verifying"
php -m | grep -i swoole
php --ri swoole | head -n 8
echo "==> Done. Swoole is installed for PHP $(php -r 'echo PHP_VERSION;')."
