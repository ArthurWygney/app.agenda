-- MIGRAÇÃO: controle de notificações (confirmação, lembretes 24h/2h, aviso à manicure)
-- Rode uma vez em bancos já existentes (em instalações novas, já está no database.sql).

USE maniagenda;

ALTER TABLE agendamentos
    ADD COLUMN confirmacao_enviada_em DATETIME NULL,
    ADD COLUMN lembrete_24h_enviado_em DATETIME NULL,
    ADD COLUMN lembrete_2h_enviado_em DATETIME NULL,
    ADD COLUMN aviso_manicure_2h_enviado_em DATETIME NULL;

-- Agendamentos que já existiam foram confirmados antes deste recurso:
-- marca como "confirmação enviada" para o script não reenviar confirmação de tudo.
UPDATE agendamentos
SET confirmacao_enviada_em = NOW()
WHERE status IN ('agendado', 'confirmado');
