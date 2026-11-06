# =====================================================================
# Dockerfile - navod, jak sestavit obraz (image) webove casti aplikace
# Zaklad: oficialni obraz s PHP 8.2 a webovym serverem Apache.
# Pouziva ho docker-compose.yml (lokalne) i hosting Render (render.yaml).
# =====================================================================
FROM php:8.2-apache

# Doinstalujeme rozsireni mysqli (pripojeni PHP k MySQL/MariaDB) - v obrazu chybi
RUN docker-php-ext-install mysqli

# "Produkcni" nastaveni PHP (mimo jine nevypisuje chyby navstevnikum)
# a vypneme hlavicku X-Powered-By, ktera by prozrazovala presnou verzi PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "expose_php = Off" > "$PHP_INI_DIR/conf.d/planodar.ini"

# Nastaveni Apache:
#   - AllowOverride All = Apache cte nase soubory .htaccess (zakaz pristupu k config.php, SQL...)
#   - ServerTokens/ServerSignature = chybove stranky neprozradi verzi Apache
#   - mod_headers = .htaccess muze nastavit cache pro styly a skripty
#   (nazev zz-... = soubor se nacte az po security.conf z Debianu, ktery by
#    ServerTokens jinak prepsal - konfigurace se nacitaji abecedne)
RUN printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\nServerTokens Prod\nServerSignature Off\n' \
        > /etc/apache2/conf-available/zz-planodar.conf \
    && a2enconf zz-planodar \
    && a2enmod headers

# Port, na kterem Apache posloucha, se bere z promenne PORT
# (hosting Render ji nastavuje sam, napr. 10000; lokalne plati vychozi 80).
# Apache umi v konfiguraci cist promenne prostredi zapisem ${PORT}.
ENV PORT=80
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

# Zkopirujeme soubory aplikace do slozky, kterou Apache zobrazuje
# (soubory patri uzivateli root - Apache je smi jen cist, ne prepsat;
#  co se nekopiruje, urcuje .dockerignore - napr. config.php s klicem)
COPY . /var/www/html/
