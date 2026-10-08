# Ревью: 2.2.2

Изменения: `d2/2.2.1-2.2.3-maxrubtsoff` → `main`. Проверено: `backend/src/Repository/ApplicationRepository.php` (ветка не меняет файл, ревью по текущему содержимому); для контекста — `backend/src/Http/ApplicationController.php`, `db/schema.sql`, `backend/config/rules.php`.

## Грубые ошибки (поломка логики или данных)
- `backend/src/Repository/ApplicationRepository.php:91-97` — SQL-инъекция в `listApplications`: `$status` и `$limit` подставляются в SQL конкатенацией строк, мимо prepared statement. Значение `$status` приходит прямо из `?status=` query-параметра (`ApplicationController.php:84,87`). На синтетике взломать нечего, но это поломка безопасности/контракта PDO.
- `backend/src/Repository/ApplicationRepository.php:22-61` — `save()` пишет три таблицы (`applications`, `vehicles`, `decisions`) без транзакции. Если упадёт второй или третий INSERT, останется заявка без машины/решения — поломка целостности данных.

## Замечания
- `backend/src/Repository/ApplicationRepository.php:99-110` — N+1: для каждой строки списка идут два дополнительных `prepare/execute` за `vehicles` и `decisions`. При лимите 50 это 1 + 50×2 запросов на каждый вызов `GET /api/applications`.
- `backend/src/Repository/ApplicationRepository.php:64` — `find()` приводит `$id` к `int` сам, а в контроллере уже есть `(int) $args['id']` (`ApplicationController.php:75`). Дублирование мелкое, но контракт не описан.

## Не проверялось
- Семантика `applicant_ref` и политика дедупликации заявок (вне файла).
- Поведение под нагрузкой/параллельные вставки — транзакционность и `lastInsertId` в гонке.
- Тесты `tests/Feature/*` на эти методы — не запускались и не читались.

Вывод: нельзя мержить — SQL-инъекция и отсутствие транзакции в `save()` нужно закрыть до мержа.
