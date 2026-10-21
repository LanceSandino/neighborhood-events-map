#!/bin/sh
# Container health: both PHP-FPM pools answer their ping endpoint over FastCGI on their Unix sockets.
set -eu
for sock in /run/php/app.sock /run/php/mail.sock; do
  SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
    cgi-fcgi -bind -connect "$sock" | grep -q pong
done
