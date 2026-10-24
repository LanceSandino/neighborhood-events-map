#!/bin/sh
# Container health: every PHP-FPM pool (www, mail, upload) answers its ping endpoint over FastCGI on its Unix socket.
set -eu
for sock in /run/php/app.sock /run/php/mail.sock /run/php/upload.sock; do
  SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect "$sock" | grep -q pong
done
