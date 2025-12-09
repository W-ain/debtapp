# ベースイメージ（PHP 8.1 + Apache）
FROM php:8.1-apache

# システムの依存関係とPHP拡張モジュールのインストール
# (データベースを使う場合、pdo_mysqlなどが必要です)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Apacheのmod_rewriteを有効化（URL書き換えを使う場合）
RUN a2enmod rewrite

# Composerのインストール
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 作業ディレクトリの設定
WORKDIR /var/www/html

# ソースコードをコンテナにコピー
COPY . /var/www/html

# Composerの依存関係をインストール
# (vendorフォルダをローカルからコピーせず、ビルド時にインストールするのが定石です)
RUN composer install --no-dev --optimize-autoloader

# ポート8080をリッスンするようにApacheを設定
# Cloud Runはデフォルトで8080番ポートへのリクエストを待ち受けます
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

# 権限の調整（Apacheユーザーがファイルを読めるように）
RUN chown -R www-data:www-data /var/www/html