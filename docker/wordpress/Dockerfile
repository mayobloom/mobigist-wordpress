FROM wordpress:php8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        gifsicle \
        libjpeg-turbo-progs \
        optipng \
        pngquant \
        webp \
    && rm -rf /var/lib/apt/lists/*
