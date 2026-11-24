# ==============================================================================
# HwAdmin 企业级生产容器镜像 Dockerfile
# 基础运行环境：PHP 8.3 FPM Alpine + 多数据库驱动 (MySQL/PostgreSQL/SQLite) + Redis + GD
# ==============================================================================

FROM php:8.3-fpm-alpine

LABEL maintainer="HwAdmin Team <support@hwadmin.cn>"
LABEL description="HwAdmin 现代化敏捷后台框架企业级运行环境"

# 国内/国际加速 Alpine 软件源并安装编译依赖与工具链
RUN sed -i 's/dl-cdn.alpinelinux.org/mirrors.aliyun.com/g' /etc/apk/repositories || true \
    && apk add --no-cache \
        bash \
        curl \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        postgresql-dev \
        sqlite-dev \
        icu-dev \
        libxml2-dev \
        linux-headers \
        $PHPIZE_DEPS

# 配置并安装 PHP 核心扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        gd \
        zip \
        bcmath \
        intl \
        opcache \
        pcntl

# 安装 PECL 扩展 (Redis)
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

# 清理构建临时依赖以缩减镜像体积
RUN apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

# 从官方镜像拷贝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 配置 Opcache 生产级高性能参数（含 PHP 8.3 JIT 推荐配置）
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.revalidate_freq=2'; \
        echo 'opcache.fast_shutdown=1'; \
        echo 'opcache.jit=tracing'; \
        echo 'opcache.jit_buffer_size=128M'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# 覆盖 PHP-FPM 进程池调优参数
COPY zz-fpm.conf /usr/local/etc/php-fpm.d/zz-fpm.conf

# 配置文件上传与内存参数
RUN { \
        echo 'upload_max_filesize=500M'; \
        echo 'post_max_size=500M'; \
        echo 'memory_limit=512M'; \
        echo 'max_execution_time=300'; \
        echo 'max_input_time=300'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# 创建项目目录并授权
WORKDIR /var/www/hwadmin/server
RUN chown -R www-data:www-data /var/www/hwadmin

EXPOSE 9000

CMD ["php-fpm"]
