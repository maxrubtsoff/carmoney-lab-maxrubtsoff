# Ревью: 2.4.1-2.4.3

Изменения: `d2/2.4.1-2.4.3-maxrubtsoff` → `main`. Diff `git diff main...HEAD -- backend/src/Domain/DecisionEngine.php` пустой, ревью по текущему содержимому файла. Проверено: `backend/src/Domain/DecisionEngine.php` (целиком), `backend/config/rules.php` (ключи `ltv`, `vehicle.mileage_review_above_km` и комментарии к порогам), `backend/src/Domain/LtvCalculator.php` (округление LTV до двух знаков), `backend/src/Domain/AssessmentService.php` (как используется решение), `backend/src/AppFactory.php` (создание объекта, grep), `tests/Unit/DecisionEngineTest.php` (набор граничных значений). Литералов порогов в `DecisionEngine.php` нет.

## Грубые ошибки (поломка логики или данных)
- `backend/src/Domain/DecisionEngine.php:40` — LTV, равный `approve_max` (60.0), попадает в `review`, а не в `approve`. Код: `$ltv < $this->approveMax`. Описание класса (`DecisionEngine.php:10`) и комментарий в `backend/config/rules.php:42` задают `LTV <= approve_max -> approve`. Значение 60.0 достижимо: `LtvCalculator.php:25` округляет LTV до двух знаков. Пример: заявка 300000 при оценочной стоимости 500000 дает LTV 60.00, решение `review`, `approved_limit` 0 (`AssessmentService.php:39`), хотя по описанию должно быть `approve` с лимитом 300000. Граница в тестах не покрыта. Нужно подтвердить, какая граница верна: `<` в коде или `<=` в описании.

## Замечания
- `backend/src/Domain/DecisionEngine.php:30-34` — конструктор не проверяет наличие ключей `approve_max` и `review_max` в `$thresholds`. При отсутствии ключа PHP выдаст предупреждение Undefined array key, а присваивание null в typed property `float` даст TypeError, то есть невнятную ошибку вместо понятного сообщения о конфиге.
- `tests/Unit/DecisionEngineTest.php:30-35` — нет граничного значения LTV = 60.0 (`approve_max`). Поэтому расхождение из грубой ошибки не ловится тестами.

## Не проверялось
- Спецификация порогов LTV в `docs/spec` и `docs/sources` не сверялась. Направление границы взято из описания класса и комментария в `rules.php`.
- `make test`, `make lint` и phpunit не запускались (по команде `make` не запускать).
- `VehicleAge`, `ApplicationValidator` и feature-тесты по пробегу (`tests/Feature/LtvEndpointHighMileageTest.php`, `tests/Feature/SaveEndpointHighMileageTest.php`) не читались.
- `ltv_by_age` в `rules.php` в этом файле не используется (задача LOAN-12), вне рамок ревью.

Вывод: нельзя мержить: граница LTV = `approve_max` (`DecisionEngine.php:40`) расходится с документированным правилом; в ветке файл не менялся относительно `main`, так что расхождение уже есть в `main`.
