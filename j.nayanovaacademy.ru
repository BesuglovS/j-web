# ==========================================
# 1. Редирект HTTP → HTTPS
# ==========================================
server {
    listen 80;
    server_name j.nayanovaacademy.ru;
    
    # Перенаправляем все запросы на HTTPS
    return 301 https://$host$request_uri;
}

# ==========================================
# 2. Основной HTTPS-сервер
# ==========================================
server {
    listen 443 ssl http2;
    server_name j.nayanovaacademy.ru;

    # --- SSL-сертификаты (ваши пути) ---
    ssl_certificate     /etc/ssl/certs/nayanovaacademy.ru/cert.pem;
    ssl_certificate_key /etc/ssl/private/nayanovaacademy.ru/key.pem;

    # --- Настройки безопасности SSL ---
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    # OCSP Stapling (закомментировано, т.к. ранее было предупреждение)
    # ssl_stapling on;
    # ssl_stapling_verify on;
    # resolver 8.8.8.8 8.8.4.4 valid=300s;
    # resolver_timeout 5s;

    # HSTS (обязывает браузер использовать только HTTPS)
    add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;

    # --- Основные параметры сайта ---
    root /var/www/j.nayanovaacademy.ru/public;
    index index.html index.htm index.php;

    # Логирование
    access_log /var/log/nginx/j.nayanovaacademy.ru.access.log;
    error_log  /var/log/nginx/j.nayanovaacademy.ru.error.log;

    # 1. Основная маршрутизация: всё через фронт-контроллер
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # 2. Обработка PHP-файлов через FastCGI
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # 3. Кэширование статики
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # 4. Блокировка скрытых файлов
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    # 5. Защита файлов вне webroot (на случай неправильной раскладки)
    location ~* \.(sql|db|md|log)$ {
        deny all;
        return 404;
    }

    # 6. Блокировка служебных/секретных файлов деплоя
    location ~* ^/(deploy\.ps1|\.env|pass\.md|\.gitignore|README\.md|PLAN\.md)$ {
        deny all;
        access_log off;
        log_not_found off;
    }
}