FROM php:8.3-cli
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
COPY src ./src
COPY views ./views
COPY public ./public
COPY database ./database
COPY bin ./bin
COPY examples ./examples
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
