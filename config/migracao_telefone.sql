-- MIGRAÇÃO: padroniza clientes.telefone para (00) 00000-0000
-- Rode uma vez em bancos já existentes (instalações novas já saem do database.sql).

USE maniagenda;

ALTER TABLE clientes
    MODIFY telefone VARCHAR(15) NOT NULL COMMENT 'Padrão (00) 00000-0000';

-- 1) Confira antes o que será convertido e o que NÃO é um celular válido
--    (esses ficam como estão e precisam ser corrigidos à mão).
--    Coluna "digitos" = telefone sem ( ) espaço - +
--
-- SELECT id, nome, telefone,
--        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+','') AS digitos
-- FROM clientes
-- WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+','')
--       NOT REGEXP '^(55)?[1-9][1-9]9[0-9]{8}$';

-- 2) Com código do país (55 + DDD + número) → remove o 55 e formata
UPDATE clientes
SET telefone = CONCAT(
    '(', SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+',''), 3, 2), ') ',
    SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+',''), 5, 5), '-',
    SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+',''), 10, 4)
)
WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+','')
      REGEXP '^55[1-9][1-9]9[0-9]{8}$';

-- 3) DDD + número (11 dígitos) → formata
UPDATE clientes
SET telefone = CONCAT(
    '(', SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+',''), 1, 2), ') ',
    SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+',''), 3, 5), '-',
    SUBSTRING(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+',''), 8, 4)
)
WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone,'(',''),')',''),' ',''),'-',''),'+','')
      REGEXP '^[1-9][1-9]9[0-9]{8}$';

-- Obs.: se o passo 2 ou 3 acusar "Duplicate entry", há dois cadastros com o mesmo
-- número escritos de formas diferentes — resolva o duplicado e rode de novo.
