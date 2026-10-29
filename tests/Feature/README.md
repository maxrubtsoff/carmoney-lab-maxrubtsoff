# tests/Feature

Сюда кладут тесты, которым нужно больше, чем один класс: поднятая база, HTTP-запрос
к сервису, проверка полного пути заявки.

Что здесь есть сейчас (защита от второй активной заявки клиента, задача MAS 3.2):

- `ApplicationDuplicateGuardTest` — 201/409/422 по `POST /api/applications`, отмена заявки, разные клиенты, аноним;
- `ApplicantRefNormalizationTest` — `applicant_ref`: trim, аноним `CL-ANON`, 422 на не-строку и длину больше 32;
- `ApplicationRepositoryTransactionTest` — откат транзакции в `save()` при сбое на `vehicles` и `decisions`, ошибки не по индексу активной заявки пробрасываются;
- `ActiveStatusesSchemaTest` — активные статусы в `active_applicant_ref` (читаются из `information_schema`) совпадают с `ApplicationRepository::ACTIVE_STATUSES`;
- `ConcurrentApplicationTest` — гонка 2 и 8 параллельных процессов с отдельными PDO;
- `LtvEndpointHighMileageTest`, `SaveEndpointHighMileageTest` — решение `review` при высоком пробеге на `/api/ltv` и при сохранении;
- `Support/` — помощники (`ApplicationApi`, `ConcurrentApplicationRunner`, `concurrent_worker.php`, `FailingPdo`).

Тесты работают на настоящей MySQL со схемой `db/schema.sql` (или после `db/migrations/001_active_applicant_unique.sql`),
подключение берётся из `DB_DSN`, `DB_USER`, `DB_PASSWORD`. Клиенты в тестах синтетические (`CL-T...`),
заявки, созданные через помощник `ApplicationApi`, тесты удаляют сами; исключение — старый `SaveEndpointHighMileageTest`,
он сохраняет заявки анонимного клиента `CL-ANON` и не убирает их (после прогона их нужно чистить вручную). Гонять их стоит на копии базы, а не на рабочей `carmoney_lab`:
для этого `DB_DSN`, `DB_USER` и `DB_PASSWORD` передают через `docker compose exec -e ...`.

Правила те же, что и для `tests/Unit`:

- один тест проверяет одно утверждение и **заканчивается assert'ом, а не действием**;
- имя теста описывает поведение: `testRejectsApplicationWithFutureProductionYear`;
- данные только синтетические;
- тест, написанный под баг, сначала должен быть красным — и коммитится отдельно от фикса.

Запуск только этого набора:

```bash
vendor/bin/phpunit --testsuite feature
```
