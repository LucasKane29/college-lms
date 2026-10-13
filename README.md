# College LMS — Docker для stage/dev

Готовий комплект для Linux-сервера: корінь репозиторію `/opt/college`, наявний Symfony-проєкт у `app/`. Docker Compose v2, вісім сервісів, окрема bridge-мережа, п'ять іменованих volumes. MinIO не використовується.

## Файли

Розпакуйте **вміст** архіву в корінь репозиторію, зберігши структуру. Не замінюйте наявні конфігурації Symfony файлами з `examples/` без об'єднання налаштувань.

```text
/opt/college/
├── docker-compose.yml
├── .env.example
├── .env                         # створюється на сервері; не комітити
├── docker/
│   ├── init-env.sh
│   ├── nginx/default.conf
│   └── php/
│       ├── Dockerfile
│       ├── php.ini
│       ├── zz-college.conf
│       └── fpm-healthcheck
├── examples/
│   ├── messenger.yaml
│   └── cache.yaml
└── app/                         # ваш Symfony, не входить в архів
    ├── composer.json
    ├── composer.lock
    ├── bin/console
    ├── config/
    └── public/index.php
```

Окремий `docker-compose.yml` потребує супровідних файлів `docker/`. Образ PHP збирається з PHP 8.4 FPM і містить Composer 2, PDO MySQL, AMQP, Redis, intl, zip, OPcache, pcntl, bcmath та sockets. DOM, XML, mbstring, curl та базові розширення беруться з офіційного PHP-образу; збірка перевіряє їх наявність.

## Перший запуск на stage

Потрібні Docker Engine з Compose v2 (рекомендовано 2.20+), OpenSSL та наявний `app/`. Виконуйте від власника репозиторію, наприклад `deploy`, який має доступ до Docker. PHP і worker працюють як `www-data` з UID/GID цього користувача.

```bash
cd /opt/college
bash docker/init-env.sh
```

Скрипт створить кореневий `.env` з окремими випадковими секретами, правами `600` і вашим UID/GID. Наявний `.env` він не змінює: у такому разі доповніть його за `.env.example`; перевірте `APP_UID` / `APP_GID` через `id -u` / `id -g`. Паролі генеруйте командою `openssl rand -hex 32`.

Кореневий `.env` належить Compose, а `app/.env` — Symfony. Передані контейнеру змінні мають пріоритет над типовими значеннями Symfony. Залишайте імена БД, користувачів і vhost URL-safe, а паролі — hex, оскільки Compose складає з них DSN без URL-кодування.

Додайте правила з `gitignore.snippet` і `gitattributes.snippet` до відповідних наявних файлів репозиторію. `app/vendor`, `app/var`, `app/public/uploads` не повинні відстежуватися Git. Власник `app/` має збігатися з UID/GID PHP; для старих файлів з іншим власником виправте права перед запуском.

```bash
mkdir -p app/var app/vendor app/public/uploads
docker compose --profile worker config --quiet
docker compose build --pull
docker compose up -d --wait --wait-timeout 240
docker compose exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader
```

`app` перевіряє готовність FPM і може запускатися до встановлення Composer-залежностей. Worker відокремлений профілем, щоб не стартувати до встановлення залежностей та міграцій. Перший `up` не означає, що Symfony вже готовий приймати користувачів.

Переконайтеся, що проєкт містить `symfony/amqp-messenger`, `symfony/doctrine-messenger`, Doctrine ORM/DBAL, Doctrine Migrations та Symfony Mailer. Якщо їх немає, додайте залежності в гілці розробки, перевірте результат і збережіть `composer.json` / `composer.lock` у Git. Наприклад, для Messenger:

```bash
docker compose exec app composer require symfony/amqp-messenger symfony/doctrine-messenger
```

Об'єднайте `examples/messenger.yaml` з `app/config/packages/messenger.yaml`: потрібен транспорт **`async`**, який споживає worker. Приклад також задає failed transport у MySQL. Додайте до `routing` реальні класи повідомлень, які мають виконуватися асинхронно. Перевірте наявні `when@dev` / `when@prod` overrides, щоб вони не підміняли потрібний транспорт.

```bash
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
docker compose exec -T app php bin/console messenger:setup-transports --no-interaction
docker compose exec -T app php bin/console cache:clear
docker compose --profile worker up -d --wait --wait-timeout 120 worker
docker compose --profile worker ps
```

`messenger:setup-transports` створює черги й таблицю failed transport з наданого прикладу. Якщо ваш проєкт керує цією таблицею міграціями, узгодьте її з ними. Redis доступний через `REDIS_URL`; для використання його як app cache об'єднайте `examples/cache.yaml` з конфігурацією Symfony. `DATABASE_URL` і `MAILER_DSN` повинні використовуватися відповідними конфігураціями Doctrine та Mailer.

## Адреси та доступ

| Сервіс | На Docker-хості | З інших контейнерів |
|---|---|---|
| College LMS | `http://127.0.0.1:8080` | `nginx:80` |
| RabbitMQ Management | `http://127.0.0.1:15672` | `rabbitmq:15672` |
| Mailpit | `http://127.0.0.1:8025` | SMTP: `mailpit:1025` |
| Portainer | `https://127.0.0.1:9443` | `portainer:9443` |
| MySQL | порт не опублікований | `mysql:3306` |
| Redis | порт не опублікований | `redis:6379` |
| AMQP | порт не опублікований | `rabbitmq:5672` |
| PHP FPM | порт не опублікований | `app:9000` |

Для віддаленого stage відкрийте SSH-тунель зі свого ПК, підставивши адресу сервера:

```bash
ssh -N -L 8080:127.0.0.1:8080 -L 15672:127.0.0.1:15672 -L 8025:127.0.0.1:8025 -L 9443:127.0.0.1:9443 deploy@SERVER_IP
```

Потім використовуйте адреси з таблиці на своєму ПК. RabbitMQ використовує `RABBITMQ_USER` / `RABBITMQ_PASSWORD` із кореневого `.env`. У Portainer створіть адміністратора при першому вході; він має самопідписаний HTTPS-сертифікат. Якщо початкове налаштування заблоковане таймером, виконайте `docker compose restart portainer` та відкрийте UI знову. Якщо версія вимагає setup token, дивіться локально `docker compose logs portainer`.

Portainer має адміністративний доступ до Docker через socket. Bind на localhost обмежує мережевий доступ до UI, але не обмежує повноваження Portainer над хостом. Не створюйте другий екземпляр цього ж стеку через UI поверх розгорнутого Compose.

Для stage-домену налаштуйте HTTPS reverse proxy на хості до `127.0.0.1:8080` і довірені адреси проксі у Symfony. TLS для самого LMS не входить до цього комплекту. `HTTP_BIND` можна змінити для Nginx; адреси адмінських UI навмисно закріплені на `127.0.0.1`.

## Режим dev

У кореневому `.env` встановіть:

```dotenv
COMPOSE_PROJECT_NAME=college-dev
APP_ENV=dev
APP_DEBUG=1
```

Для одночасних stage і dev на одному Docker-хості задайте **різні** `HTTP_PORT`, `RABBITMQ_UI_PORT`, `MAILPIT_UI_PORT`, `PORTAINER_HTTPS_PORT`. Не змінюйте назву вже розгорнутого stage-проєкту без потреби: вона визначає імена його volumes і мережі. Dev отримує свої volumes завдяки іншій назві проєкту.

Встановлюйте Composer dev-залежності (наведена команда `composer install` їх не виключає). Код монтується з `app/`, OPcache перевіряє зміни файлів. Worker завантажує код у пам'ять: після змін перезапускайте `docker compose --profile worker restart worker`.

## Оновлення та збереження даних

Імена `app` / `worker` і профіль `worker` відповідають deploy-скрипту з попереднього чату. Перед зміною коду зупиняйте worker, після Composer / міграцій / очищення кешу запускайте його знову. У наявному скрипті використайте `docker compose up -d --wait --wait-timeout 240 --remove-orphans`, щоб дочекатися готовності інфраструктури. Новий сервер потребує первинного налаштування з розділу вище.

Це конфігурація stage/dev із bind mount коду: оновлення не є атомарними й не гарантують відсутності коротких помилок вебзапитів під час Composer/міграцій.

MySQL, Redis (AOF), RabbitMQ, листи Mailpit і конфігурація Portainer зберігаються у власних іменованих volumes. `app/` з vendor, var та uploads зберігається на хості. `docker compose down` не видаляє іменовані volumes; `down -v` видаляє їхні дані. Volumes не замінюють резервні копії. Не використовуйте `git clean -fdx` на сервері з локальними даними та секретами.

MySQL і RabbitMQ застосовують початкових користувачів/паролі при першій ініціалізації сховища. Зміна пароля лише у `.env` не змінює його в наявній базі/брокері: спочатку змініть облікові дані штатними засобами сервісу, потім узгодьте `.env`.

Worker коректно отримує SIGTERM через Docker init та має 120 секунд для завершення поточного повідомлення; для довших задач збільште `stop_grace_period`. Він автоматично перезапускається після ліміту часу або пам'яті. Обробники мають бути ідемпотентними. Іменований volume RabbitMQ забезпечує збереження файлів брокера; для надійної доставки також потрібні durable queues/exchanges і persistent messages (типова поведінка Symfony AMQP), а не лише volume.

Healthchecks перевіряють Nginx HTTP, FPM ping, авторизований SQL-запит, Redis PING, стан/порти RabbitMQ, Mailpit readyz, Portainer та процес Messenger. Вони не перевіряють бізнес-операції LMS або прогрес обробки черги. Compose чекає `service_healthy` під час старту; статус `unhealthy` сам по собі не перезапускає контейнер. `restart: unless-stopped` реагує на завершення процесу.

Теги `nginx:stable-alpine`, `php:8.4-fpm-bookworm`, `mysql:8.4`, `redis:7-alpine`, `rabbitmq:4-management-alpine`, `portainer/portainer-ce:alpine-lts` отримують оновлення у своїх гілках. Для відтворюваних deployments після перевірки зафіксуйте конкретні patch-версії/digests. Оновлення major/minor RabbitMQ узгоджуйте з офіційною процедурою upgrade. Alpine-варіант Portainer містить `wget` для HTTPS healthcheck; перевірка локального самопідписаного сертифіката в цьому probe вимкнена.

## Перевірка та джерела

Комплект перевіряється статично: YAML, офіційна схема Compose, посилання на файли, залежності сервісів, порти та volumes. Docker у середовищі генерації відсутній: образи тут не збиралися і контейнерний запуск не перевірявся. Перший запуск на сервері має пройти наведені `config`, `build` та `up --wait`.

- [Docker Compose: залежності та готовність сервісів](https://docs.docker.com/compose/how-tos/startup-order/)
- [Офіційний PHP-образ та розширення](https://hub.docker.com/_/php)
- [Symfony Messenger: AMQP, retries та завершення worker](https://symfony.com/doc/current/messenger.html)
- [Mailpit Docker та збереження листів](https://mailpit.axllent.org/docs/install/docker/)
- [RabbitMQ: перевірки готовності](https://www.rabbitmq.com/docs/monitoring)
- [Portainer: CLI](https://docs.portainer.io/advanced/cli)
