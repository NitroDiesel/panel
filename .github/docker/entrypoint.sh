#!/bin/ash -e
cd /app

## Containers started as root keep the original behaviour: nginx on 80/443, Let's Encrypt
## and cron. Containers started as any other user (e.g. "--user nginx") serve plain HTTP on
## 8080 and only write to /tmp, /app/var and /app/storage, so they can also run with a
## read-only root filesystem.
if [ "$(id -u)" = "0" ]; then
  UNPRIVILEGED=false
else
  UNPRIVILEGED=true
  export NGINX_CONF=/etc/nginx/nginx-unprivileged.conf
  for dir in /app/var /app/storage; do
    if [ ! -w "$dir" ]; then
      echo "$dir is not writable by uid $(id -u). Mount a volume owned by the nginx user there."
      exit 1
    fi
  done
fi

if [ "$UNPRIVILEGED" = "false" ]; then
  mkdir -p /var/log/panel/logs/ /var/log/supervisord/ /var/log/nginx/ /var/log/php7/ \
    && chmod 777 /var/log/panel/logs/ \
    && ln -s /app/storage/logs/ /var/log/panel/
fi

## the image already links /app/.env to /app/var/.env; only recreate the link if it was replaced
link_env() {
  if [ "$(readlink /app/.env)" != "/app/var/.env" ]; then
    rm -rf /app/.env
    ln -s /app/var/.env /app/
  fi
}

## check for .env file and generate app keys if missing
if [ -f /app/var/.env ]; then
  echo "external vars exist."
  link_env
else
  echo "external vars don't exist."
  touch /app/var/.env

  ## generate a key in the same format as key:generate (32 random bytes, base64). artisan
  ## can't be used here because the application refuses to boot without a key.
  if [ -z $APP_KEY ]; then
     echo -e "Generating key."
     APP_KEY=$(php -r 'echo "base64:".base64_encode(random_bytes(32));')
     echo -e "Generated app key."
     echo -e "APP_KEY=$APP_KEY" > /app/var/.env
  else
    echo -e "APP_KEY exists in environment, using that."
    echo -e "APP_KEY=$APP_KEY" > /app/var/.env
  fi

  ## generate a random salt for hashids if not provided
  if [ -z $HASHIDS_SALT ]; then
     echo -e "Generating hashids salt."
     HASHIDS_SALT=$(cat /dev/urandom | tr -dc 'a-zA-Z0-9!@#$%^&*()_+?><~' | fold -w 20 | head -n 1)
     echo -e "Generated hashids salt."
     echo -e "HASHIDS_SALT=$HASHIDS_SALT" >> /app/var/.env
  else
    echo -e "HASHIDS_SALT exists in environment, using that."
    echo -e "HASHIDS_SALT=$HASHIDS_SALT" >> /app/var/.env
  fi

  link_env
fi

echo "Checking if https is required."
if [ "$UNPRIVILEGED" = "true" ]; then
  echo "Not running as root: serving plain HTTP on port 8080. Terminate TLS at a reverse proxy."
  if [ $LE_EMAIL ]; then
    echo "LE_EMAIL is ignored when not running as root."
  fi
elif [ -f /etc/nginx/http.d/panel.conf ]; then
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

if [[ -z $DB_PORT ]]; then
  echo -e "DB_PORT not specified, defaulting to 3306"
  DB_PORT=3306
fi

## check log folder permissions
echo "Checking log folder permissions."
if [ "$UNPRIVILEGED" = "false" ] && [ "$(stat -c %U:%G /app/storage/logs)" != "nginx" ]; then
  echo "Fixing log folder permissions."
  chown -R nginx: /app/storage/logs/
fi

## check for DB up before starting the panel
echo "Checking database status."
until nc -z -v -w30 $DB_HOST $DB_PORT
do
  echo "Waiting for database connection..."
  # wait for 1 seconds before check again
  sleep 1
done

## make sure the db is set up
if [ "${APP_AUTO_MIGRATE:-true}" = "true" ]; then
  echo -e "Migrating and Seeding D.B"
  php artisan migrate --seed --force
else
  echo -e "APP_AUTO_MIGRATE is not \"true\", skipping migrations."
fi

## cron only renews Let's Encrypt certificates now; the Laravel scheduler runs under supervisord
if [ "$UNPRIVILEGED" = "false" ]; then
  echo -e "Starting cron jobs."
  crond -L /var/log/crond -l 5
fi

echo -e "Starting supervisord."
exec "$@"
