# ベースイメージ
FROM php:8.1-apache

# 1. システムの依存関係をまとめてインストール
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. GD拡張機能を設定（JPEGとFreetypeを有効化）
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

# 3. PHP拡張モジュールをまとめてインストール
RUN docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd

# 4. Apacheのmod_rewriteを有効化
RUN a2enmod rewrite

# 5. Composerのインストール
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 6. 作業ディレクトリの設定
WORKDIR /var/www/html

# 7. ソースコードのコピー
COPY . /var/www/html

# 8. Composerの依存関係をインストール
RUN composer install --no-dev --optimize-autoloader

# 9. Cloud Run用のポート設定 (80 -> 8080)
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

# 10. 権限の調整
RUN chown -R www-data:www-data /var/www/html
