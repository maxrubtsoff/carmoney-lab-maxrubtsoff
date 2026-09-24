# kilo_hello.md

1) Сервис — учебный проект практикума М3 `carmoney-lab` для **предварительной оценки заявки на заём под ПТС** (считает LTV и возвращает `approve` / `review` / `reject`); PHP-бэкенд + форма, MySQL, Docker.
2) В `Makefile` есть `make up` (поднять `backend` + `db`), `make down`, `make ps`, `make logs`, `make install` (`composer install`), `make test` (PHPUnit локально или в контейнере), `make lint` (`php -l` по `backend/` и `tests/`), `make seed` (залить `db/seed.sql`), `make help`. В `docker-compose.yml` — сервисы `backend` (PHP, порт `${APP_PORT:-8080}`) и `db` (mysql:8.0, порт `${DB_PORT:-3307}`, healthcheck `mysqladmin ping`), init-скрипты `db/schema.sql` и `db/seed.sql`, том `db-data`.
3) Папка `backend/src/Domain/` (`DecisionEngine.php`/`AssessmentService.php`) — тут считается решение `approve` / `review` / `reject` по заявке.

модель: training-2026-09-minimax-m3 (powered by stg/training-2026-09-minimax-m3)