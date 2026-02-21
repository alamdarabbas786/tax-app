FROM php:8.2-cli

WORKDIR /app

RUN apt-get update \
  && apt-get install -y --no-install-recommends \
     git unzip libpq-dev \
  && docker-php-ext-install pdo pdo_pgsql \
  && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock* /app/
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
  && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
  && rm composer-setup.php \
  && composer install --no-interaction --no-progress --prefer-dist || true

COPY . /app

EXPOSE 3000
CMD ["php", "-S", "0.0.0.0:3000", "-t", "public"]