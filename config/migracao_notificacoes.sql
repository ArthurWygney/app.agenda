-- MIGRAÇÃO (RF04): fila e histórico de notificações por SMS.
-- Rode uma vez em bancos já existentes (em instalações novas, já está no database.sql).
-- Requer a migração anterior migracao_lembretes.sql (colunas de controle em agendamentos).
--
-- Se você já tinha criado a tabela `notificacoes` na versão com WhatsApp, rode em vez
-- disto o arquivo migracao_notificacoes_somente_sms.sql.

USE maniagenda;

CREATE TABLE IF NOT EXISTS notificacoes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    agendamento_id BIGINT UNSIGNED NULL,
    inicio_agendado DATETIME NULL COMMENT 'Data/hora do atendimento quando a mensagem foi criada (detecta remarcação)',

    tipo VARCHAR(30) NOT NULL COMMENT 'confirmacao, lembrete_24h, lembrete_2h, aviso_manicure, confirmacao_presenca, orientacao, teste',
    destinatario ENUM('cliente', 'manicure') NOT NULL DEFAULT 'cliente',
    telefone VARCHAR(20) NOT NULL,
    mensagem TEXT NOT NULL,

    provedor VARCHAR(20) NULL COMMENT 'twilio, zenvia ou simulado',

    status ENUM('pendente', 'enviando', 'enviado', 'entregue', 'lido', 'falhou', 'cancelado')
        NOT NULL DEFAULT 'pendente',
    tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_erro VARCHAR(500) NULL,
    provider_message_id VARCHAR(120) NULL,
    proxima_tentativa DATETIME NULL,

    criado_em DATETIME NOT NULL,
    atualizado_em DATETIME NOT NULL,
    enviado_em DATETIME NULL,
    entregue_em DATETIME NULL,
    lido_em DATETIME NULL,

    INDEX idx_notif_fila (status, proxima_tentativa),
    INDEX idx_notif_agendamento (agendamento_id),
    INDEX idx_notif_provider (provider_message_id),

    CONSTRAINT fk_notif_agendamento
        FOREIGN KEY (agendamento_id)
        REFERENCES agendamentos(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;
