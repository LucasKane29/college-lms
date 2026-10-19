# Авторизація College LMS

Сторінки `/login` та `/registration` використовують Symfony Security, Doctrine,
Symfony Forms, Tailwind CSS 4, Turbo Drive та Stimulus через AssetMapper.

- Реєстрація: ім’я та прізвище, email, пароль і підтвердження пароля.
- Email зберігається в нижньому регістрі, має унікальний індекс у БД.
- Паролі хешуються через `UserPasswordHasherInterface`; відкритий пароль не зберігається.
- Форми реєстрації, входу та виходу захищені CSRF-токенами.
- Невдала валідація повертає HTTP 422; успішна реєстрація та вхід — HTTP 303.
- Після реєстрації відкривається `/login`, після входу — захищений `/account`.
- Вихід: POST `/logout` із CSRF-токеном.
- Stimulus перемикає видимість пароля, Turbo обробляє навігацію й надсилання форм.

## Запуск

Команди Docker Compose виконуються з кореня репозиторію, де налаштований `.env`.
На Windows виконуйте їх у WSL. Сервіс PHP має назву `app`.

```bash
docker compose exec -T app composer install
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T app php bin/console cache:clear
```

Сторінки: `http://127.0.0.1:8080/login` і `http://127.0.0.1:8080/registration`.

## Стилі

Зібраний `assets/styles/app.css` включений у репозиторій: Node.js не потрібен
під час роботи PHP-застосунку. Після зміни шаблонів або класів Tailwind перебудуйте CSS:

```bash
cd app
npm ci
npm run build:css
# Або під час розробки:
npm run watch:css
```

Для production після збирання CSS виконайте `php bin/console asset-map:compile`
в контейнері PHP перед публікацією статичних ресурсів.

## Тести

```bash
docker compose exec -T app php bin/console lint:twig templates
docker compose exec -T app php bin/console lint:yaml config translations
docker compose exec -T app php vendor/bin/phpunit
```

`phpunit.dist.xml` примусово задає окрему SQLite-базу `var/auth-test.db`.
Потрібне PHP-розширення `pdo_sqlite`. Тестовий набір перебудовує схему лише
цієї бази та перевіряє її шлях перед змінами. Робоча MySQL-база
для функціональних тестів не використовується.
