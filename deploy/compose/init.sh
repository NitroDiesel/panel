#!/bin/sh
# Creates the two files the stack needs, without overwriting existing ones:
#   .env       generated database and Redis passwords (read by docker compose)
#   panel.env  panel settings, copied from panel.env.example for you to edit
set -eu
cd "$(dirname "$0")"
umask 077

secret() {
  # 32 characters from [A-Za-z0-9], safe to use unquoted in env files and URLs
  LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32
}

if [ -e .env ]; then
  echo ".env already exists, leaving it alone."
else
  {
    echo "DB_PASSWORD=$(secret)"
    echo "DB_ROOT_PASSWORD=$(secret)"
    echo "REDIS_PASSWORD=$(secret)"
  } > .env
  echo "Wrote .env with generated passwords."
fi

if [ -e panel.env ]; then
  echo "panel.env already exists, leaving it alone."
else
  cp panel.env.example panel.env
  echo "Wrote panel.env. Set APP_URL and the mail settings in it before starting the stack."
fi
