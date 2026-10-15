#!/bin/ash
# Used by the image HEALTHCHECK. Non-root containers serve on 8080; root (legacy) containers
# serve on 80, or redirect 80 to 443 when Let's Encrypt is enabled.
if [ "$(id -u)" != "0" ]; then
  exec wget -q -O /dev/null -T 4 http://127.0.0.1:8080/up
elif [ -n "$LE_EMAIL" ]; then
  exec wget -q -O /dev/null -T 4 --no-check-certificate https://127.0.0.1/up
else
  exec wget -q -O /dev/null -T 4 http://127.0.0.1/up
fi
