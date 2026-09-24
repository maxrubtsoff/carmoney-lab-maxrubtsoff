# Карта кода: решение approve / review / reject

Промпт агенту (ask, модель GLM 5.3, файлы: `@backend/src/Domain/`, `@backend/config/rules.php`):
«Не меняя файлы, объясни, как считается решение approve/review/reject по заявке: какие
файлы участвуют, какие функции вызываются и в каком порядке. Затем скажи, куда встанет
правило «пробег авто не больше 400 000 км, иначе review». Отдельно: что в коде уже
сейчас проверяется про пробег.»

Файлы агент не менял — только читал.

## 1. Как считается решение approve / review / reject

### Участвующие файлы

| Файл | Роль |
|---|---|
| `backend/config/rules.php` | Справочник порогов (числа), код их только читает |
| `backend/src/AppFactory.php` | Связывание: загружает `rules.php` и раздаёт секции классам через конструкторы |
| `backend/src/Http/ApplicationController.php` | Точка входа: `create()` (POST /api/applications) и `ltv()` (POST /api/ltv) |
| `backend/src/Domain/AssessmentService.php` | Оркестратор: валидация → LTV → решение → лимит |
| `backend/src/Domain/ApplicationValidator.php` | Валидация полей заявки по правилам, возвращает нормализованный массив |
| `backend/src/Domain/VinValidator.php` | Формат VIN (длина 17, A–Z0–9, без I/O/Q — из `rules['vin']`) |
| `backend/src/Domain/VehicleAge.php` | `age = текущий год − год выпуска` |
| `backend/src/Domain/LtvCalculator.php` | `LTV = round(requested_amount / market_value × 100, 2)` |
| `backend/src/Domain/DecisionEngine.php` | Единственное место, где выбирается approve/review/reject |
| `backend/src/Domain/ValidationException.php` | Ошибки валидации (поле → сообщение), контроллер отдаёт их с HTTP 422 |

### Порядок вызовов (файл → функция → порядок)

1. `ApplicationController::create()` / `ltv()` → `AssessmentService::assess($payload)`.
2. `ApplicationValidator::validate()` (`ApplicationValidator.php:24`) — проверяет VIN, год,
   пробег (0…500 000), стоимость, сумму, срок. Ошибка → `ValidationException` → HTTP 422,
   решение не считается. Успех → нормализованный массив `input`.
3. `LtvCalculator::calculate()` (`LtvCalculator.php:15`) — LTV в процентах, 2 знака.
4. `DecisionEngine::decide(float $ltv)` (`DecisionEngine.php:30`):
   - `$ltv < approveMax` (60.0) → `approve`
   - `$ltv <= reviewMax` (85.0) → `review`
   - иначе → `reject`

   Нюанс: сравнение строгое (`<`), а докблок класса и комментарий в `rules.php` пишут
   `LTV <= approve_max -> approve` — на границе 60.0 код и документация расходятся.
5. `AssessmentService::assess()` — собирает результат: `vehicle_age`, `ltv`, `decision`,
   `approved_limit` (сумма при approve, иначе 0).
6. `create()` сохраняет через `ApplicationRepository::save()`; `ltv()` — только считает,
   без сохранения.

## 2. Точка вставки правила «пробег ≤ 400 000 км, иначе review»

Сегодня решение зависит **только от LTV** — пробег в `decide()` не участвует, он проверяется
только на этапе валидации (см. п.3). Значит новое правило — это правило *решения*, а не
валидации: класть его в `ApplicationValidator` нельзя (там ошибка = 422 без решения, а
нужно именно `review`).

**Вариант A — в `DecisionEngine::decide()`:**
- добавить порог в `rules.php` (например `'review_mileage_above' => 400000`);
- сигнатура `decide(float $ltv)` → `decide(float $ltv, int $mileage)`;
- в `AssessmentService::assess()` пробросить `$input['mileage']` (он уже есть);
- обновить конструктор `DecisionEngine`, `AppFactory.php:37`, `DecisionEngineTest`.

**Вариант B — в `AssessmentService::assess()`** сразу после строки, где считается
`$decision`: если пробег > порога и решение было `approve` → заменить на `REVIEW`.
Минус: `AssessmentService` сейчас вообще не получает `rules`, порог придётся прокидывать
новым параметром.

**Есть:** `$input['mileage']` (нормализованный int, 0…500 000) доступен в `assess()` после
`validate()`; константа `DecisionEngine::REVIEW`; механизм конфигурации rules.php → AppFactory.

**Не хватает:** порога 400 000 в `rules.php` (есть только `max_mileage_km=500000` —
это другое, валидационный потолок); пробега как входа `decide()` (вариант A) или
конфига у `AssessmentService` (вариант B); тестов на граничные значения 399999/400000/400001.

Открытый вопрос: интервал действия правила — 400 000 < пробег ≤ 500 000 (выше 500 000
отсекается валидацией раньше). И нужно решить: правило должно только понижать approve→review,
или перезаписывать любое решение (тогда reject при пробеге 410 000 стал бы review — смягчение)?

## 3. Что в коде уже проверяется про пробег

Единственная проверка — `ApplicationValidator.php:43-46`:

```php
$mileage = (int) ($payload['mileage'] ?? -1);
if ($mileage < 0 || $mileage > $this->rules['vehicle']['max_mileage_km']) {
    $errors['mileage'] = sprintf('Пробег от 0 до %d км', ...); // max_mileage_km = 500000
}
```

- пробег — целое число 0…500 000 включительно, иначе `ValidationException` → HTTP 422;
- отсутствие поля → трактуется как `-1` → та же ошибка;
- порог берётся из `rules['vehicle']['max_mileage_km']` (`rules.php`).

Больше ни на что пробег не влияет: в `LtvCalculator` и `DecisionEngine::decide()` он не
попадает. Сохраняется в БД (`vehicles.mileage_km`), отдаётся обратно в `find()`. Других
проверок (связь с возрастом, влияние на лимит, порог 400 000) в коде нет.

---

**Проверка на достоверность:** сверено с реальными файлами репозитория — пороги
`approve_max=60.0`, `review_max=85.0`, `max_mileage_km=500000` из `rules.php`, строгое
сравнение `<` в `DecisionEngine::decide()` и номера строк в `ApplicationValidator.php`
совпадают. Агент ничего не выдумал.
