# A throwaway ILIAS to test the plugin against. Not for production use.
#
# Pinned to 10.11, the version the plugin is built for. The SOAP surface and the
# user import XML differ between majors.
#
# The release tarball ships vendor/ and node_modules/ already built, so there is
# no composer or npm step here.

FROM php:8.3-apache

ARG ILIAS_VERSION=10.11

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libxml2-dev libxslt1-dev libcurl4-openssl-dev libonig-dev libmagickwand-dev \
        zip unzip imagemagick ghostscript default-mysql-client \
        curl ca-certificates $PHPIZE_DEPS; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" gd zip xsl mysqli pdo_mysql opcache soap; \
    # ILIAS checks for ext-imagick at install time and refuses without it. The
    # PECL release does not build against PHP 8.3, so take it from master.
    curl -fsSL -o /tmp/imagick.tgz https://github.com/Imagick/imagick/archive/refs/heads/master.tar.gz; \
    mkdir -p /tmp/imagick && tar xzf /tmp/imagick.tgz -C /tmp/imagick --strip-components=1; \
    cd /tmp/imagick && phpize && ./configure && make -j"$(nproc)" && make install; \
    docker-php-ext-enable imagick; \
    cd / && rm -rf /tmp/imagick /tmp/imagick.tgz; \
    rm -rf /var/lib/apt/lists/*

# ILIAS wants generous limits for imports and the setup run itself.
RUN { \
      echo 'memory_limit = 512M'; \
      echo 'post_max_size = 128M'; \
      echo 'upload_max_filesize = 128M'; \
      echo 'max_execution_time = 600'; \
      echo 'session.gc_maxlifetime = 14400'; \
    } > /usr/local/etc/php/conf.d/ilias.ini

# ILIAS 10 serves from public/, everything else must stay out of the webroot.
#
# Apache also listens on 8080 so that ILIAS's configured base URL
# (http://localhost:8080) resolves identically inside the container and from the
# host. ILIAS loads its own WSDL over that URL while serving a SOAP call, so if
# the port is only valid on the host, every SOAP call fails with
# "Parsing WSDL: Couldn't load from 'http://localhost:8080/…'".
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' \
      /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf; \
    sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:80 *:8080>!' \
      /etc/apache2/sites-available/000-default.conf; \
    echo 'Listen 8080' >> /etc/apache2/ports.conf; \
    a2enmod rewrite

WORKDIR /var/www/html
RUN set -eux; \
    curl -fsSL -o /tmp/ilias.tar.gz \
      "https://github.com/ILIAS-eLearning/ILIAS/releases/download/v${ILIAS_VERSION}/ILIAS-${ILIAS_VERSION}.tar.gz"; \
    tar xzf /tmp/ilias.tar.gz --strip-components=1 -C /var/www/html; \
    rm /tmp/ilias.tar.gz; \
    mkdir -p /var/iliasdata /var/www/html/logs; \
    chown -R www-data:www-data /var/www/html /var/iliasdata

COPY setup-config.json /opt/ilias/setup-config.json
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 8080
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
