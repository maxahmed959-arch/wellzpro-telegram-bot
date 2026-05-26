FROM php:8.2-cli-alpine
RUN apk add --no-cache curl
WORKDIR /app
COPY . .
RUN chmod +x docker-entrypoint.sh \
 && mkdir -p data/sessions data/orders data/admins data/notify_map public
EXPOSE 8080
ENTRYPOINT ["./docker-entrypoint.sh"]
