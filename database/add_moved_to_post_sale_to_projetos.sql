ALTER TABLE projetos
  ADD COLUMN moved_to_post_sale TINYINT(1) NOT NULL DEFAULT 0 AFTER status;