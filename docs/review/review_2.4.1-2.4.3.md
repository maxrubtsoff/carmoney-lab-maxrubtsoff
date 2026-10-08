# Ревью: 2.4.1-2.4.3

Изменения: `d2/2.4.1-2.4.3-maxrubtsoff` → `main`. Диапазон `git diff main...HEAD` по файлу пуст, ревью по текущему содержимому `backend/src/Repository/ApplicationRepository.php`. Для контекста прочитаны: `backend/src/Http/ApplicationController.php` (вызовы `save` строка 34, `find` строка 75, `listApplications` строки 84-87), `db/schema.sql` (InnoDB, таблицы `applications`, `vehicles`, `decisions`), `backend/src/Database.php` (PDO: `ATTR_EMULATE_PREPARES => false`, строки 22-24), `backend/config/rules.php`. Тесты не читались и не запускались.

## Грубые ошибки (поломка логики или данных)
- `backend/src/Repository/ApplicationRepository.php:91-93` — SQL-инъекция в `listApplications`: `$status` подставляется в SQL конкатенацией (`" WHERE status = '" . $status . "'"`), мимо prepared statement. Значение приходит из query-параметра `?status=` (`ApplicationController.php:84`, передача в строке 87). `ATTR_EMULATE_PREPARES => false` (`Database.php:24`) эффекта не даёт: строка уже собрана до `query()`. Нужен bind-параметр.
- `backend/src/Repository/ApplicationRepository.php:22-61` — `save()` пишет три таблицы (INSERT в `applications` строки 24-35, `vehicles` строки 37-47, `decisions` строки 49-58) без транзакции. Если второй или третий INSERT упадёт, останется заявка со статусом `decided` без машины или решения. Нужны `beginTransaction` / `commit` / `rollBack`.

## Замечания
- `backend/src/Repository/ApplicationRepository.php:100-107` — N+1: на каждую строку списка два дополнительных `prepare/execute` (за `vehicles` и `decisions`). При лимите 50 это до 101 запроса на `GET /api/applications`. Можно одним JOIN или `IN (...)`.
- `backend/src/Repository/ApplicationRepository.php:87` — литерал `50` как значение по умолчанию для `$limit`. Это лимит выборки, а AGENTS.md и code-rules (п.1) требуют брать лимиты из `backend/config/rules.php`. Если считать это технической пагинацией, а не бизнес-лимитом, решение за автором. Сейчас в `rules.php` такого ключа нет.
- `backend/src/Repository/ApplicationRepository.php:95` — `$limit` конкатенируется в SQL. Тип `int` защищает от инъекции, но для единообразия с исправлением строк 91-93 лучше тоже передавать через bind (или `(int)` и `LIMIT` через `bindValue` с `PDO::PARAM_INT`).

## Не проверялось
- Тесты `tests/Feature/*` и `tests/Unit/*` на `ApplicationRepository` — не читались, `make test` не запускался.
- Поведение `save()` при гонке параллельных заявок и корректность `lastInsertId()` под нагрузкой.
- Семантика `applicant_ref` и политика дедупликации заявок (вне файла).
- Находки совпадают с ранее записанным `docs/review/review_2.2.2.md` (тот же файл, ветка его не меняет): инъекция и отсутствие транзакции в текущем коде не исправлены.

Вывод: нельзя мержить — SQL-инъекция через `?status=` в `listApplications` и отсутствие транзакции в `save()` нужно закрыть до мержа.
