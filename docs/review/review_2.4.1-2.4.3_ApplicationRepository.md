# Ревью: 2.4.1-2.4.3

Изменения: `d2/2.4.1-2.4.3-maxrubtsoff` → `main`. Изменений по файлу нет: `git diff main...HEAD -- backend/src/Repository/ApplicationRepository.php` пуст, поэтому ревью по текущему содержимому файла. Проверено: `backend/src/Repository/ApplicationRepository.php` (целиком), `backend/src/Http/ApplicationController.php` (вызывающий код: строки 34, 75, 84–87), `backend/config/rules.php` (проверка литералов), поиск `beginTransaction` в `backend/` (нет совпадений).

## Грубые ошибки (поломка логики или данных)
- `backend/src/Repository/ApplicationRepository.php:92` — SQL-инъекция в `listApplications`: значение `$status` конкатенируется в текст запроса без связанного параметра. `$status` приходит из query-строки `?status=` (`backend/src/Http/ApplicationController.php:84`, передаётся в строке 87 без валидации). Через `GET /api/applications?status=...` можно подставить произвольный SQL.
- `backend/src/Repository/ApplicationRepository.php:24–58` — `save()` делает три независимых INSERT (`applications`, `vehicles`, `decisions`) без транзакции; `beginTransaction` в `backend/` не найден. Если вставка в `vehicles` или `decisions` упадёт, останется заявка со статусом `decided` без автомобиля и без решения, а контроллер вернёт ошибку.

## Замечания
- `backend/src/Repository/ApplicationRepository.php:95` — `$limit` тоже вставляется в SQL строкой. Сейчас тип `int` защищает от инъекции, но ограничения сверху нет, а вызывающий код `limit` не передаёт, так что значение фиксированное 50.
- `backend/src/Repository/ApplicationRepository.php:87` — литерал `50` как лимит выборки. Если это бизнес-лимит, по `.kilo/rules/code-rules.md` п.4 его место в `backend/config/rules.php`. Если технический размер страницы, оставить с комментарием.
- `backend/src/Repository/ApplicationRepository.php:100–107` — N+1: на каждую заявку отдельные запросы к `vehicles` и `decisions` (два запроса на строку списка). Не ошибка, но при `limit=50` это 101 запрос на список.

## Не проверялось
- Схема `db/schema.sql` (внешние ключи, уникальность, типы колонок) не открывалась, поэтому не известно, какие ограничения БД ловят частичную запись из `save()`.
- Поведение `listApplications` при реальных значениях `status` и эксплуатация инъекции не запускались: код и тесты не запускались, `make test` не выполнялся.
- Файлы из `tests/` и `frontend/` не смотрел.

Вывод: нельзя мержить (SQL-инъекция через `status` в `listApplications`, частичная запись в `save()` без транзакции).
