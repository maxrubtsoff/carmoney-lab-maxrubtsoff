-- Миграция 001: защита от второй активной заявки одного клиента.
-- Для баз, созданных до появления колонки active_applicant_ref (на новых базах её уже создаёт db/schema.sql).
--
-- Применение к поднятой базе (make-цели для миграций нет, команда через docker compose exec):
--   docker compose exec -T db mysql -ulab -plab carmoney_lab < db/migrations/001_active_applicant_unique.sql
--
-- Что делает: добавляет STORED generated column active_applicant_ref и UNIQUE по ней.
-- Активные статусы: new, decided (должны совпадать с ApplicationRepository::ACTIVE_STATUSES).
-- Аноним CL-ANON и cancelled получают NULL и под ограничение не попадают.
--
-- Если у какого-то клиента уже больше одной активной заявки, ALTER TABLE падает с ошибкой
-- 1062 Duplicate entry '<клиент>' for key 'applications.uq_applications_active_applicant'
-- и ничего не меняет (ALTER в MySQL 8 атомарен). Данные скрипт не правит: какую из заявок
-- закрыть, решает человек (POST /api/applications/{id}/cancel или UPDATE status = 'cancelled').
-- Список таких клиентов скрипт выводит первым запросом, до ALTER.
-- Повторный запуск безопасен: если колонка уже есть, скрипт ничего не меняет.
--
-- Хранимой процедуры нет намеренно: SIGNAL оставил бы её в базе после остановки скрипта.
-- Условное выполнение сделано через PREPARE/EXECUTE, они живут только в сессии клиента.

SET NAMES utf8mb4;

-- Информационный отчёт: клиенты с дублями активных заявок (пусто — можно применять).
SELECT MIN(applicant_ref) AS applicant_ref, COUNT(*) AS active_applications, GROUP_CONCAT(id ORDER BY id) AS application_ids
FROM applications
WHERE status IN ('new', 'decided')
  AND applicant_ref COLLATE utf8mb4_0900_bin <> 'CL-ANON'
GROUP BY applicant_ref COLLATE utf8mb4_0900_bin
HAVING COUNT(*) > 1;

SET @migration_001_sql = IF(
    (SELECT COUNT(*)
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'applications'
       AND COLUMN_NAME = 'active_applicant_ref') = 0,
    'ALTER TABLE applications
        ADD COLUMN active_applicant_ref VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin
            GENERATED ALWAYS AS (
                IF(status IN (''new'', ''decided'') AND applicant_ref COLLATE utf8mb4_0900_bin <> ''CL-ANON'',
                   applicant_ref, NULL)
            ) STORED,
        ADD UNIQUE KEY uq_applications_active_applicant (active_applicant_ref)',
    'DO 0'
);

PREPARE migration_001 FROM @migration_001_sql;
EXECUTE migration_001;
DEALLOCATE PREPARE migration_001;
