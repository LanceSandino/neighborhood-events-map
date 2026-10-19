#!/bin/sh
# Container health: PHP-FPM answers its ping endpoint over FastCGI.
set -eu
SCRIPT_NAME=/fpm-ping SCRIPT_FILENAME=/fpm-ping REQUEST_METHOD=GET \
  cgi-fcgi -bind -connect 127.0.0.1:9000 | grep -q pong
