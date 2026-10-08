---
description: Ревью ApplicationRepository перед мержем по скиллу code-review
agent: code
---
Сделай ревью `backend/src/Repository/ApplicationRepository.php` перед мержем.

Порядок, формат результата и чек-лист — в скилле `code-review`
(`.kilo/skills/code-review/SKILL.md`, шаблон `.kilo/skills/code-review/template.md`).
Возьми скилл и работай строго по нему, здесь его шаги не повторяются.

Что добавляет команда к скиллу:
1. Изменения: `git diff main...HEAD -- backend/src/Repository/ApplicationRepository.php`.
   Если diff пустой — ревью по текущему содержимому файла, и это пишется в строке «Проверено».
2. ID задачи — из имени текущей ветки (`d2/2.4.1-2.4.3-<login>` → `2.4.1-2.4.3`).
3. Результат: `docs/review/review_<ID задачи>.md`; в чат — резюме из скилла и путь к файлу.

Чего не делать: код и тесты не править, `make`, коммиты и push не запускать,
файлы за пределами `docs/review/` не создавать, в ревью не включать другие файлы,
кроме тех, что нужны для контекста (контекст перечислить в «Проверено»).
