-- Схема учебного сервиса carmoney-lab. MySQL 8.
-- Применяется автоматически при первом запуске контейнера db.

CREATE TABLE IF NOT EXISTS applications (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    applicant_ref   VARCHAR(32)  NOT NULL COMMENT 'синтетический идентификатор клиента, ПДн не храним',
    requested_amount INT UNSIGNED NOT NULL,
    term_months     SMALLINT UNSIGNED NOT NULL,
    status          ENUM('new', 'decided', 'cancelled') NOT NULL DEFAULT 'new',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Защита от второй активной заявки одного клиента. Значение заполнено только у активных
    -- заявок (status new/decided) именованного клиента; для cancelled и для анонима CL-ANON
    -- оно NULL, а UNIQUE допускает сколько угодно NULL. Список активных статусов должен
    -- совпадать с ApplicationRepository::ACTIVE_STATUSES. Сравнение по точному значению:
    -- коллация utf8mb4_0900_bin без нормализации регистра и пробелов.
    active_applicant_ref VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin
        GENERATED ALWAYS AS (
            IF(status IN ('new', 'decided') AND applicant_ref COLLATE utf8mb4_0900_bin <> 'CL-ANON',
               applicant_ref, NULL)
        ) STORED,
    PRIMARY KEY (id),
    KEY idx_applications_status (status),
    UNIQUE KEY uq_applications_active_applicant (active_applicant_ref)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS vehicles (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id  INT UNSIGNED NOT NULL,
    vin             CHAR(17) NOT NULL,
    make            VARCHAR(40) NULL,
    model           VARCHAR(40) NULL,
    production_year SMALLINT UNSIGNED NOT NULL,
    mileage_km      INT UNSIGNED NOT NULL,
    market_value    INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_vehicles_application (application_id),
    CONSTRAINT fk_vehicles_application FOREIGN KEY (application_id)
        REFERENCES applications (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS decisions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id  INT UNSIGNED NOT NULL,
    ltv             DECIMAL(6, 2) NOT NULL,
    decision        ENUM('approve', 'review', 'reject') NOT NULL,
    approved_limit  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_decisions_application (application_id),
    CONSTRAINT fk_decisions_application FOREIGN KEY (application_id)
        REFERENCES applications (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
