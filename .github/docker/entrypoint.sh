#!/bin/ash -e
cd /app

mkdir -p /var/log/panel/logs/ /var/log/supervisord/ /var/log/nginx/ /var/log/php7/ \
  && chmod 777 /var/log/panel/logs/ \
  && ln -s /app/storage/logs/ /var/log/panel/

## Reuse persisted secrets, but never invent encryption material at runtime.
if [ -f /app/var/.env ]; then
  echo "external vars exist."
  if ! grep -q '^APP_KEY=.' /app/var/.env && [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required and is missing from both the environment and /app/var/.env." >&2
    exit 1
  fi
  if ! grep -q '^HASHIDS_SALT=.' /app/var/.env && [ -z "${HASHIDS_SALT:-}" ]; then
    echo "HASHIDS_SALT is required and is missing from both the environment and /app/var/.env." >&2
    exit 1
  fi
  rm -f /app/.env
  ln -s /app/var/.env /app/
else
  echo "external vars don't exist."
  if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY must be provided on the first container start." >&2
    exit 1
  fi
  if [ -z "${HASHIDS_SALT:-}" ]; then
    echo "HASHIDS_SALT must be provided on the first container start." >&2
    exit 1
  fi

  rm -f /app/.env
  umask 027
  printf 'APP_KEY=%s\nHASHIDS_SALT=%s\n' "$APP_KEY" "$HASHIDS_SALT" > /app/var/.env
  chown nginx:nginx /app/var/.env
  ln -s /app/var/.env /app/
fi

for variable in DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD REDIS_HOST; do
  value="$(printenv "$variable" || true)"
  if [ -z "$value" ]; then
    echo "$variable must be provided; refusing to start with an implicit runtime configuration." >&2
    exit 1
  fi
done

echo "Checking if https is required."
if [ -f /etc/nginx/http.d/panel.conf ]; then
  echo "Using nginx config already in place."
  if [ $LE_EMAIL ]; then
    echo "Checking for cert update"
    certbot certonly -d $(echo $APP_URL | sed 's~http[s]*://~~g')  --standalone -m $LE_EMAIL --agree-tos -n
  else
    echo "No letsencrypt email is set"
  fi
else
  echo "Checking if letsencrypt email is set."
  if [ -z $LE_EMAIL ]; then
    echo "No letsencrypt email is set using http config."
    cp .github/docker/default.conf /etc/nginx/http.d/panel.conf
  else
    echo "writing ssl config"
    cp .github/docker/default_ssl.conf /etc/nginx/http.d/panel.conf
    echo "updating ssl config for domain"
    sed -i "s|<domain>|$(echo $APP_URL | sed 's~http[s]*://~~g')|g" /etc/nginx/http.d/panel.conf
    echo "generating certs"
    certbot certonly -d $(echo $APP_URL | sed 's~http[s]*://~~g')  --standalone -m $LE_EMAIL --agree-tos -n
  fi
  echo "Removing the default nginx config"
  rm -rf /etc/nginx/http.d/default.conf
fi

if [ -z "${DB_PORT:-}" ]; then
  echo -e "DB_PORT not specified, defaulting to 3306"
  DB_PORT=3306
fi

## check log folder permissions
echo "Checking log folder permissions."
if [ "$(stat -c %U:%G /app/storage/logs)" != "nginx" ]; then
  echo "Fixing log folder permissions."
  chown -R nginx: /app/storage/logs/
fi

## check for DB up before starting the panel
echo "Checking database status."
until nc -z -v -w30 "$DB_HOST" "$DB_PORT"
do
  echo "Waiting for database connection..."
  # wait for 1 seconds before check again
  sleep 1
done

## make sure the db is set up
echo -e "Migrating and Seeding D.B"
php artisan migrate --seed --force

## start cronjobs for the queue
echo -e "Starting cron jobs."
crond -L /var/log/crond -l 5

echo -e "Starting supervisord."
exec "$@"
