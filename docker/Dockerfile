FROM php:8.2-apache
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && { echo 'upload_max_filesize=256M'; echo 'post_max_size=256M'; echo 'memory_limit=512M'; echo 'date.timezone=Asia/Karachi'; } > /usr/local/etc/php/conf.d/medicare.ini
