-- Automacao de migracao de Projetos para Pos-venda via MySQL Event Scheduler
-- Requisitos:
-- 1) MySQL Event Scheduler habilitado (SET GLOBAL event_scheduler = ON;)
-- 2) Coluna em projeto_stages: post_sale_enabled e post_sale_target_stage_id
-- 3) Colunas em projetos: status_changed_at e due_days

-- Opcional: habilitar scheduler (exige privilegio GLOBAL)
-- SET GLOBAL event_scheduler = ON;

DROP EVENT IF EXISTS ev_project_to_post_sale_automation;

DELIMITER $$
CREATE EVENT ev_project_to_post_sale_automation
ON SCHEDULE EVERY 10 MINUTE
DO
BEGIN
    INSERT INTO pos_venda (
        user_id,
        project_id,
        client_name,
        installation_date,
        next_maintenance,
        notes,
        stage,
        created_at,
        updated_at
    )
    SELECT
        p.user_id,
        p.id,
        p.client_name,
        DATE(COALESCE(p.closed_date, p.created_at, p.status_changed_at, p.updated_at)) AS installation_date,
        DATE_ADD(DATE(COALESCE(p.closed_date, p.created_at, p.status_changed_at, p.updated_at)), INTERVAL 6 MONTH) AS next_maintenance,
        CONCAT('Migrado automaticamente de Projetos para Pos-venda apos ', GREATEST(1, COALESCE(p.due_days, 30)), ' dias (prazo do card).'),
        pvst.name AS stage,
        NOW(),
        NOW()
    FROM projetos p
    INNER JOIN projeto_stages ps
        ON ps.user_id = p.user_id
       AND TRIM(ps.name) COLLATE utf8mb4_unicode_ci = TRIM(p.status) COLLATE utf8mb4_unicode_ci
    LEFT JOIN pos_venda_stages pvst
        ON pvst.user_id = p.user_id
       AND pvst.id = ps.post_sale_target_stage_id
    LEFT JOIN pos_venda pv
        ON pv.project_id = p.id
       AND pv.user_id = p.user_id
    WHERE COALESCE(ps.post_sale_enabled, 0) = 1
      AND pv.id IS NULL
      AND DATEDIFF(
            CURDATE(),
            DATE(COALESCE(p.closed_date, p.created_at, p.status_changed_at, p.updated_at))
                    ) >= GREATEST(1, COALESCE(p.due_days, 30));

        UPDATE projetos p
        INNER JOIN pos_venda pv
                ON pv.project_id = p.id
             AND pv.user_id = p.user_id
        SET p.moved_to_post_sale = 1,
                p.updated_at = NOW()
        WHERE COALESCE(p.moved_to_post_sale, 0) = 0;
END$$
DELIMITER ;
