# Runs the benchmarks on Linux, the way most production images run PHP:
# the official Alpine image, with extensions built by php-extension-installer.
#
#   docker build -f benchmarks/Dockerfile -t picasso-bench .
#   docker run --rm picasso-bench                 # every scenario
#   docker run --rm picasso-bench hit herd        # some of them
#
# Pin a PHP version with --build-arg PHP_VERSION=8.2 (Glide 4 and the vips
# driver 4.x need 8.3+; Composer picks Glide 3 below that).

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# gd is built with the libgd bundled in PHP (AVIF, WebP, JPEG); imagick and vips
# use Alpine's ImageMagick and libvips, with their HEIF/AVIF modules.
RUN install-php-extensions gd imagick ffi opcache pcntl \
    && apk add --no-cache vips vips-heif imagemagick-heic \
    && { \
        echo 'opcache.enable_cli=1'; \
        echo 'memory_limit=-1'; \
        echo 'ffi.enable=true'; \
    } > /usr/local/etc/php/conf.d/benchmarks.ini

WORKDIR /app
COPY . .

# No lock file: resolve for this PHP version, plus the vips driver (needs FFI and libvips)
RUN composer require --dev --no-interaction --no-progress --no-update intervention/image-driver-vips \
    && composer update --no-interaction --no-progress --prefer-dist \
    && php -r 'printf("GD %s, AVIF %s | Imagick %s | libvips %s\n", gd_info()["GD Version"], var_export(gd_info()["AVIF Support"] ?? false, true), Imagick::getVersion()["versionString"], trim((string) shell_exec("apk info -v 2>/dev/null | grep ^vips-[0-9]")));' \
    && php -r 'echo "Imagick AVIF: ", in_array("AVIF", Imagick::queryFormats("AVIF"), true) ? "yes" : "no", "\n";'

ENTRYPOINT ["php", "benchmarks/run.php"]
