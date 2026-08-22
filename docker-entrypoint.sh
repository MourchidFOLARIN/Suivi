#!/bin/bash
set -e

# Render fournit $PORT dynamiquement — on le configure dans Apache
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-enabled/000-default.conf
fi

# Lancement d'Apache au premier plan
exec apache2-foreground
