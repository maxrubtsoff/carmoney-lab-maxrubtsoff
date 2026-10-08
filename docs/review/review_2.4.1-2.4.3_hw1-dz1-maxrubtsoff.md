# Ревью: 2.4.1-2.4.3

Изменения: `hw1/dz1-maxrubtsoff` → `main`. Ветка уже влита в `main` merge-коммитом `b95991f` (PR #6), поэтому ревью по диффу `c29d3cf...hw1/dz1-maxrubtsoff` (`M^1...<ветка>`, 12 файлов, +528 / −68). Проверено: `.github/workflows/pr-checks.yml`, `backend/config/rules.php`, `backend/src/AppFactory.php`, `backend/src/Database.php`, `backend/src/Domain/AssessmentService.php`, `backend/src/Domain/DecisionEngine.php`, `backend/src/Domain/ApplicationValidator.php`, `backend/src/Domain/LtvCalculator.php`, `backend/src/Http/ApplicationController.php`, `backend/src/Repository/ApplicationRepository.php` (только `find`, `listApplications`), `db/schema.sql`, `tests/Feature/*MileageTest.php`, `tests/Unit/{ApplicationValidatorTest,AssessmentServiceTest,DecisionEngineTest}.php`, `docs/spec/spec_MILEAGE.md` (контекст для требований REQ-MILEAGE-01…06 и AC). Тесты не запускались (нет MySQL и vendor, `make` по правилам команды не запускается); соответствие ожиданий тестов спеке проверено чтением.

## Грубые ошибки (поломка логики или данных)
- нет

## Замечания
- `backend/src/Domain/DecisionEngine.php:10` и `backend/config/rules.php:43` — докблок пишет `LTV <= approve_max -> approve`, а код `backend/src/Domain/DecisionEngine.php:40` использует `$ltv < $this->approveMax`: LTV ровно 60.0 даёт `review`. Расхождение было до ветки и спека (`docs/spec/spec_MILEAGE.md`) фиксирует его как известное, не меняя. Ветка переписала докблок рядом и оставила несоответствие: стоит хотя бы отметить в докблоке.
- `backend/src/AppFactory.php:28` — `Database::connect()` открывает PDO сразу при `AppFactory::create()`, поэтому оба новых Feature-теста требуют живой MySQL, включая `LtvEndpointHighMileageTest`, которому БД не нужна. `AGENTS.md` (раздел 2) пишет «Без Docker: composer install, make test. Остального нет», и это теперь неверно для Feature-набора.
- `tests/Feature/SaveEndpointHighMileageTest.php` — тест сохраняет заявку в БД и не удаляет её. В CI база свежая, на локальной базе строки накапливаются при каждом прогоне.
- Вне диффа, для сведения: `backend/src/Repository/ApplicationRepository.php:92` вставляет `$status` в SQL конкатенацией, без параметров. Ветка этот метод не меняла, в вердикт не входит.

## Соответствие code-rules
- Литералов `400000` и `500000` в `backend/src/` и `frontend/` нет (проверено `git grep`). Порог читается из конфига: `backend/src/AppFactory.php:37` передаёт `$rules['vehicle']['mileage_review_above_km']`, ключ добавлен в `backend/config/rules.php:24-26` с комментарием.
- Числа существующих ожиданий (`max_mileage_km = 500000`, пороги LTV 60.0 / 85.0) не менялись.

## Не проверялось
- Реальный прогон PHPUnit и CI-джоба `test` с MySQL-сервисом.
- Поведение на границах LTV 60.0 и 85.0 при пробеге выше порога: по спеке не проверяется.
- Frontend: спека говорит, что форма уже отправляет пробег, не сверялось.

Вывод: можно мержить (грубых ошибок не найдено; замечания не блокируют; ветка уже в `main`, вывод ретроспективный).
