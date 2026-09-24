# Spec MILEAGE — правило «пробег не больше 400 000 км, иначе review»

Спека по `docs/intent/intent_MILEAGE.md`: что считается сделанным. Как
реализовывать — `docs/plan/plan_MILEAGE.md`, здесь этого нет.

Сокращения для источников: «интервью N» — ответ на вопрос N в
`docs/intent/grill_MILEAGE.md`; «код: <файл>» — текущий код репозитория.

## 1. Входит / не входит

Входит:

- Правило уровня решения: при валидном пробеге строго больше 400 000 км
  итоговое решение — `review`, каким бы ни было решение по LTV.
- Порог 400 000 в конфиге бизнес-правил: `vehicle.mileage_review_above_km`.
- Действие правила в обеих точках, где считается решение: сохранение заявки
  (`POST /api/applications`) и предварительный расчёт LTV (`POST /api/ltv`).
- `approved_limit = 0` при `review` по пробегу.

Не входит (не делаем в этой задаче):

- Валидатор пробега и потолок 500 000 (`vehicle.max_mileage_km`) — без
  изменений. — «интервью 5»
- `frontend/` — форма уже отправляет пробег. — «интервью 5»
- БД и схема — колонка пробега уже существует. — «интервью 5»,
  «код: db/schema.sql»
- Расхождение кода и документации на границе LTV 60.0 (строгое `<` против
  `<=`) — не чиним. — «интервью 5»
- Поле причины review (LTV против пробега) в ответе API — не добавляем. —
  «интервью 5»
- Обновление `docs/setup/code_map.md` — следующий проход по карте. —
  «интервью 5»
- Пересчёт решений уже сохранённых заявок (в т.ч. seed) — открытый вопрос 6
  из intent перенесён за рамки задачи, спека требований к пересчёту не
  предъявляет (см. раздел 5).

## 2. Требования

- **REQ-MILEAGE-01.** Порог правила пробега хранится в конфиге бизнес-правил
  `backend/config/rules.php`, секция `vehicle`, ключ `mileage_review_above_km`
  со значением `400000`. — «интервью 4», «код: backend/config/rules.php»
- **REQ-MILEAGE-02.** При валидном пробеге строго больше 400 000 итоговое
  решение — `review` (правило уровня решения, а не валидации); окно действия
  на валидном пробеге — 400 001…500 000. — intent §1, «интервью 1»,
  «код: backend/src/Domain/ApplicationValidator.php»
- **REQ-MILEAGE-03.** Правило перекрывает любое решение по LTV, включая
  `reject` → `review` (сознательное смягчение, подтверждено человеком). —
  «интервью 3»
- **REQ-MILEAGE-04.** При пробеге меньше 400 000 или ровно 400 000 правило не
  применяется: итоговое решение определяется только текущими ветками LTV
  (`approve` / `review` / `reject` по порогам `ltv.approve_max = 60.0` и
  `ltv.review_max = 85.0`), ветки не меняются. — «интервью 1»,
  «код: backend/config/rules.php»
- **REQ-MILEAGE-05.** Валидация пробега не меняется: отсутствующий (поле не
  передано или `null`) или невалидный (вне 0…500 000) пробег даёт HTTP 422 с
  ошибкой «Пробег от 0 до 500000 км» до расчёта решения; валидационный
  потолок `vehicle.max_mileage_km = 500000` — отдельное число, остаётся как
  есть; правило не превращает такие заявки в `review`. — «интервью 2»,
  «код: backend/src/Domain/ApplicationValidator.php»
- **REQ-MILEAGE-06.** При `review` по пробегу `approved_limit = 0`, как у
  любого не-approve решения. — «код:
  backend/src/Domain/AssessmentService.php»
- **REQ-MILEAGE-07.** Правило действует везде, где считается решение: при
  сохранении заявки (`POST /api/applications`) и в предварительном расчёте LTV
  (`POST /api/ltv`). — «код: backend/src/Http/ApplicationController.php»,
  «интервью 5»

Примечание (процессное, не REQ): порог 400 000 и семантика правила —
решение риск-менеджмента; значения `rules.php` и ожидания существующих
тестов не подгоняются ради зелёного прогона (AGENTS.md п.5, intent §3).

## 3. Критерии приёмки

Базовая заявка в AC, если не указано иное: все поля валидны, LTV = 50.0 —
approve-зона по LTV (LTV задаётся парой сумма/стоимость, например
`requested_amount = 500000` при `market_value = 1000000`). Значения LTV
50.0 / 70.0 / 95.0 — тестовые, из середин зон approve / review / reject по
порогам `ltv.approve_max = 60.0` и `ltv.review_max = 85.0`; поведение ровно
на границах LTV в этой задаче не проверяется и не меняется (расхождение на
60.0 — вне задачи, «интервью 5»).

- **AC-MILEAGE-01** (REQ-MILEAGE-01). Given конфиг
  `backend/config/rules.php`; When конфиг загружается; Then в секции
  `vehicle` есть ключ `mileage_review_above_km = 400000`, отличный от
  `max_mileage_km = 500000` — оба ключа на месте.
- **AC-MILEAGE-02** (REQ-MILEAGE-04). Given базовая заявка с пробегом
  `399999`; When считается решение; Then `decision = "approve"` и
  `approved_limit = 500000` (запрошенной сумме).
- **AC-MILEAGE-03** (REQ-MILEAGE-04). Given базовая заявка с пробегом
  `400000`; When считается решение; Then `decision = "approve"` — граница не
  срабатывает, правило строго больше («интервью 1»); `approved_limit =
  500000`.
- **AC-MILEAGE-04** (REQ-MILEAGE-02, REQ-MILEAGE-06). Given базовая заявка с
  пробегом `400001`; When считается решение; Then `decision = "review"` (не
  approve) и `approved_limit = 0`.
- **AC-MILEAGE-05** (REQ-MILEAGE-03, REQ-MILEAGE-06). Given заявка с
  пробегом `400001` и LTV 95.0 (reject-зона); When считается решение; Then
  `decision = "review"` (не reject) и `approved_limit = 0`.
- **AC-MILEAGE-06** (REQ-MILEAGE-02). Given заявка с пробегом `400001` и LTV
  70.0 (review-зона по LTV); When считается решение; Then
  `decision = "review"` — то же, что было бы по LTV.
- **AC-MILEAGE-07** (REQ-MILEAGE-04). Given заявка с пробегом `399999` и LTV
  70.0; When считается решение; Then `decision = "review"` (LTV-ветка review
  без изменений), `approved_limit = 0`.
- **AC-MILEAGE-08** (REQ-MILEAGE-04). Given заявка с пробегом `399999` и LTV
  95.0; When считается решение; Then `decision = "reject"` (LTV-ветка reject
  без изменений), `approved_limit = 0`.
- **AC-MILEAGE-09** (REQ-MILEAGE-02, REQ-MILEAGE-06). Given базовая заявка с
  пробегом `500000` — верх валидного диапазона; When считается решение;
  Then `decision = "review"` и `approved_limit = 0`.
- **AC-MILEAGE-10** (REQ-MILEAGE-05). Given заявка с пустым или неизвестным
  пробегом (поле `mileage` не передано или `null`); When запрос уходит в
  расчёт решения; Then HTTP 422, ошибка `mileage` = «Пробег от 0 до 500000
  км», решение не вычисляется, заявка не сохраняется.
- **AC-MILEAGE-11** (REQ-MILEAGE-05). Given заявка с пробегом `500001` или
  `-1`; When запрос уходит в расчёт решения; Then HTTP 422 с той же ошибкой
  «Пробег от 0 до 500000 км» — не `review` и не другое решение.
- **AC-MILEAGE-12** (REQ-MILEAGE-07). Given заявка с пробегом `400001`, LTV
  50.0; When `POST /api/ltv`; Then HTTP 200, `decision = "review"`,
  `approved_limit = 0`.
- **AC-MILEAGE-13** (REQ-MILEAGE-07). Given та же заявка; When `POST
  /api/applications`; Then HTTP 201, `decision = "review"`,
  `approved_limit = 0`, заявка сохранена с этим решением (видна с ним в
  `GET /api/applications/{id}`).

## 4. Граничные значения и источники чисел

| Пробег, км | Ожидание | AC |
|---|---|---|
| не передан / `null` | 422 «Пробег от 0 до 500000 км», решение не вычисляется | AC-MILEAGE-10 |
| `-1` и ниже | 422, та же ошибка | AC-MILEAGE-11 |
| `399999` | решение по LTV (при LTV 50.0 — approve) | AC-MILEAGE-02, 07, 08 |
| `400000` | решение по LTV — граница не срабатывает | AC-MILEAGE-03 |
| `400001` | `review` при любом решении по LTV, `approved_limit = 0` | AC-MILEAGE-04, 05, 06 |
| `500000` | `review` — верх окна действия правила на валидном пробеге | AC-MILEAGE-09 |
| `500001` | 422 до расчёта решения | AC-MILEAGE-11 |

Источники чисел:

- `400000` — «интервью 1», «интервью 4» (решение риск-менеджмента).
- `399999` и `400001` — производные от порога: ближайшее значение снизу и
  на единицу выше, границы для проверки, не бизнес-числа.
- `0` и `500000` — «код: backend/src/Domain/ApplicationValidator.php»,
  «код: backend/config/rules.php» (`vehicle.max_mileage_km`).
- `60.0` и `85.0` — «код: backend/config/rules.php» (`ltv.approve_max`,
  `ltv.review_max`).
- LTV `50.0` / `70.0` / `95.0` и пары сумма/стоимость (`500000/1000000` и
  аналогичные) — тестовые значения из середин зон, не пороги.
- `422` — «код: backend/src/Http/ApplicationController.php».
- `approved_limit = 0` — «код: backend/src/Domain/AssessmentService.php».

## 5. Open questions из intent

| # | Вопрос из intent | Статус |
|---|---|---|
| 1 | Граница 400 000 включительно или нет | закрыт («интервью 1»: строго больше, 400 000 → решение по LTV) → REQ-MILEAGE-02, REQ-MILEAGE-04 |
| 2 | Поведение при отсутствии пробега | закрыт («интервью 2»: остаётся 422) → REQ-MILEAGE-05 |
| 3 | Перекрывает ли правило reject | закрыт («интервью 3»: любое решение → review) → REQ-MILEAGE-03 |
| 4 | Размещение порога в конфиге | закрыт («интервью 4»: `vehicle.mileage_review_above_km`) → REQ-MILEAGE-01 |
| 5 | Границы задачи | закрыт («интервью 5»: раздел 1 «Входит / не входит») |
| 6 | Пересчитывать ли решения уже сохранённых заявок (в т.ч. seed) | перенесён: остаётся открытым за рамками задачи MILEAGE — в интервью не обсуждался. Спека требований к пересчёту не предъявляет; по текущему коду решение фиксируется при сохранении заявки («код: backend/src/Http/ApplicationController.php», «код: backend/src/Repository/ApplicationRepository.php»), т.е. без отдельной задачи правило действует только на новые расчёты. Нужен отдельный вопрос риск-менеджменту |

## 6. Покрытие REQ → AC

| REQ | AC |
|---|---|
| REQ-MILEAGE-01 | AC-MILEAGE-01 |
| REQ-MILEAGE-02 | AC-MILEAGE-04, 06, 09 |
| REQ-MILEAGE-03 | AC-MILEAGE-05 |
| REQ-MILEAGE-04 | AC-MILEAGE-02, 03, 07, 08 |
| REQ-MILEAGE-05 | AC-MILEAGE-10, 11 |
| REQ-MILEAGE-06 | AC-MILEAGE-04, 05, 09 |
| REQ-MILEAGE-07 | AC-MILEAGE-12, 13 |

Сверка с intent: REQ-01…07 выводятся из замысла (§1) и constraints (§3);
требований сверх intent нет, «не входит» (§5) — раздел 1, open questions (§4)
— раздел 5.
