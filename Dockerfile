FROM php:8.2-cli-alpine
WORKDIR /app
COPY . .
CMD ["php", "bot.php"]
