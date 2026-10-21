#!/bin/sh
# Generates /tmp/nginx-trusted-proxy.conf at container start (nginx image entrypoint hook).
# Trusted for X-Forwarded-For / X-Forwarded-Proto: loopback and this container's default gateway —
# the source address of connections through the published 127.0.0.1 port, i.e. the host's reverse
# proxy or tunnel — plus any CIDRs in NGINX_TRUSTED_PROXIES (space-separated), e.g. a
# proxy or tunnel container on the same network. Every other peer is taken at its real address.
set -eu
out=/tmp/nginx-trusted-proxy.conf
gw=$(awk '$2 == "00000000" { print $3; exit }' /proc/net/route 2>/dev/null || true)
trusted="127.0.0.1"
if [ -n "$gw" ]; then
  trusted="$trusted $(echo "$gw" | awk '{ printf "%d.%d.%d.%d", "0x" substr($1,7,2), "0x" substr($1,5,2), "0x" substr($1,3,2), "0x" substr($1,1,2) }')"
fi
for cidr in ${NGINX_TRUSTED_PROXIES:-}; do
  if echo "$cidr" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}(/[0-9]{1,2})?$|^[0-9A-Fa-f:]+(/[0-9]{1,3})?$'; then
    trusted="$trusted $cidr"
  else
    echo "trusted-proxy: ignoring invalid NGINX_TRUSTED_PROXIES entry: $cidr" >&2
  fi
done
{
  echo "# generated at container start by trusted-proxy.sh"
  for t in $trusted; do echo "set_real_ip_from $t;"; done
  echo 'geo $realip_remote_addr $from_trusted_proxy {'
  echo '    default 0;'
  for t in $trusted; do echo "    $t 1;"; done
  echo '}'
} > "$out"
echo "trusted-proxy: $trusted"
