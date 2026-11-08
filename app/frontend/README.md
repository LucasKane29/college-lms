# Стилі інтерфейсу

Весь інтерфейс використовує Tailwind CSS 4: авторизація, навігація, каталоги,
форми, календарі та модальні вікна. Вхідний файл — `styles/tailwind.css`,
результат збірки — `../assets/styles/app.css`. Зібраний файл зберігається
в репозиторії та підключається через `assets/app.js` і Symfony AssetMapper.

Із директорії `app/`:

```bash
npm ci
npm run build:css
# Спостереження за змінами під час розробки:
npm run watch:css
```

Tailwind сканує `templates/` та `assets/controllers/`. Використовуйте повні
назви утиліт у шаблонах, а не конкатенацію частин класів. Наприклад, колонки
днів календаря вибираються зі статичного списку `col-start-1`…`col-start-7`.
Для production після збірки CSS виконайте `php bin/console asset-map:compile`.
Node.js потрібен для збірки, а не для роботи PHP-застосунку.

## Автоматична збірка при деплої

`composer install` і `composer update` автоматично запускають встановлення
Node-залежностей із lock-файла та збірку Tailwind перед командами Symfony.
`--no-dev` Composer не вимикає збірку: CLI Tailwind встановлюється через
`npm ci --include=dev`. Не використовуйте `--no-scripts`, якщо очікуєте
автоматичну збірку. Помилка npm зупиняє Composer і деплой.

PHP-образ містить Node.js 22 та npm. Після першого оновлення Dockerfile
потрібно перебудувати образ і перестворити PHP-контейнер:

```bash
docker compose build app
docker compose up -d --no-deps --wait app
docker compose exec -T app composer install --no-interaction --prefer-dist --optimize-autoloader
```

Наступні деплої, що запускають `composer install`, перебудовуватимуть CSS
автоматично. В `APP_ENV=prod` після команд Symfony Composer також автоматично
запускає `asset-map:compile`, щоб опублікувати актуальні стилі; у dev/test
публікація пропускається. При оновленні спільного PHP-образу
також перестворіть worker у звичайному процесі деплою.

## Запуск із WSL

Для копії проєкту в Linux-файловій системі запускайте Linux-версії Node/npm
у терміналі WSL. Windows `npm.cmd` не підтримує поточний каталог виду
`\\wsl.localhost\Ubuntu-24.04\...`: CMD переходить до каталогу Windows,
і CLI Tailwind не знаходиться. У PHPStorm виберіть Node.js interpreter
із WSL або запускайте збірку в Docker:

```bash
docker compose exec -T app composer run build:css
```

Ця команда доступна після перебудови PHP-образу. На локальному Linux/WSL
із установленим Node.js альтернативою залишається `npm ci` та
`npm run build:css` із каталогу `app/`.

Спільна тема форм `templates/form/fields.html.twig` оформлює поля, підписи,
помилки та підказки утилітами Tailwind. У власних шаблонах також використовуйте
утиліти: окремі CSS-файли для навігації, адміністративних сторінок і розкладу
більше не підключаються.

`data-ui` — стабільні маркери для поведінки та перевірок сторінок. Вони
відокремлюють JavaScript і функціональні тести від класів оформлення.
Стан бокового меню зберігається в `data-collapsed`, а його вигляд визначають
варіанти `data-*` та `group-data-*` Tailwind.

Сторонній Flatpickr генерує власну розмітку. Його незмінені стилі бібліотеки
включені до шару `components` тієї самої збірки; оформлення під інтерфейс
задано через `@apply` у вхідному файлі Tailwind. Окремий адаптер у CSS
і додатковий імпорт CSS із JavaScript не потрібні.
