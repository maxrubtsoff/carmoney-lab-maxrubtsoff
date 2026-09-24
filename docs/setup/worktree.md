# Worktree: два агента в разных копиях репо

## git worktree list (из основной копии)

```
$ git worktree list
/c/1C/Курс CM                                     03e8d3c [d1/1.2.1-1.2.3-maxrubtsoff]
/c/1C/Курс CM/.kilo/worktrees/tests-review         03e8d3c [worktree/tests-review]
```

(пути в формате Git Bash/MINGW64, как в вашем терминале; проверьте командой `git worktree list`
у себя — должно совпасть)

## Вторая сессия

Agent Manager в Kilo не запускался (панель не отвечала), поэтому по договорённости с
преподавателем эту часть выполнил вручную, сам, в папке `.kilo/worktrees/tests-review`
на ветке `worktree/tests-review` — без агента, файлы не менял.

Промпт (тот же, что для второй сессии): «Не меняй файлы. Перечисли тесты в tests/Unit/:
какой файл что проверяет, одной строкой на файл. В конце скажи, в какой папке и на
какой ветке ты сейчас работаешь.»

Тесты в `tests/Unit/`:

- `ApplicationValidatorTest.php` — валидация заявки: нормализация VIN, отказ по году
  в будущем, отказ по сумме ниже минимума, сбор всех ошибок сразу.
- `AssessmentServiceTest.php` — сквозной сценарий оценки: approve при низком LTV с
  лимитом = запрошенной сумме, review при среднем LTV с нулевым лимитом, reject при
  высоком LTV.
- `DecisionEngineTest.php` — решение по LTV на наборе пороговых значений (approve/review/reject).
- `LtvCalculatorTest.php` — расчёт LTV в процентах на разных суммах, и ошибки при
  нулевой стоимости авто / неположительной сумме.
- `VinValidatorTest.php` — проверка формата VIN на наборе валидных/невалидных строк.

Папка: `.kilo/worktrees/tests-review`
Ветка: `worktree/tests-review`
