-- Adiciona a coluna de etapa inicial no fluxo de projetos
ALTER TABLE projeto_stages
  ADD COLUMN is_initial TINYINT(1) NOT NULL DEFAULT 0 AFTER position;

-- Opcional: para registros antigos, marca a primeira etapa de cada usuario como inicial
UPDATE projeto_stages ps
JOIN (
  SELECT user_id, MIN(position) AS min_pos
  FROM projeto_stages
  GROUP BY user_id
) first_stage ON first_stage.user_id = ps.user_id AND first_stage.min_pos = ps.position
LEFT JOIN (
  SELECT user_id, SUM(CASE WHEN is_initial = 1 THEN 1 ELSE 0 END) AS total_initial
  FROM projeto_stages
  GROUP BY user_id
) init_check ON init_check.user_id = ps.user_id
SET ps.is_initial = 1
WHERE COALESCE(init_check.total_initial, 0) = 0;
