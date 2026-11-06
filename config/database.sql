
-- MANIAGENDA - BANCO DE DADOS


DROP DATABASE IF EXISTS maniagenda;
CREATE DATABASE maniagenda
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE maniagenda;

-- TABELA: ADMINISTRADORES

CREATE TABLE administradores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TABELA: CLIENTES

CREATE TABLE clientes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    telefone VARCHAR(15) NOT NULL UNIQUE COMMENT 'Padrão (00) 00000-0000',
    email VARCHAR(150) NOT NULL UNIQUE,
    endereco VARCHAR(255),
    senha VARCHAR(255) NOT NULL,
    blacklist BOOLEAN DEFAULT FALSE,
    faltas_consecutivas TINYINT DEFAULT 0,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TABELA: SERVIÇOS

CREATE TABLE servicos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    preco DECIMAL(10,2) NOT NULL,
    duracao INT NOT NULL COMMENT 'Duração em minutos',
    ativo BOOLEAN DEFAULT TRUE
);

-- TABELA: AGENDAMENTOS

CREATE TABLE agendamentos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    cliente_id BIGINT UNSIGNED NOT NULL,
    servico_id BIGINT UNSIGNED NOT NULL,

    data DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fim TIME NOT NULL,

    status ENUM(
        'agendado',
        'confirmado',
        'concluido',
        'nao_compareceu',
        'cancelado'
    ) DEFAULT 'agendado',

    observacoes TEXT,

    -- Controle das notificações (preenchido por scripts/enviar_lembretes.php).
    -- NULL = ainda não enviada. Zerado quando o agendamento é remarcado.
    confirmacao_enviada_em DATETIME NULL,
    lembrete_24h_enviado_em DATETIME NULL,
    lembrete_2h_enviado_em DATETIME NULL,
    aviso_manicure_2h_enviado_em DATETIME NULL,

    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_cliente
        FOREIGN KEY (cliente_id)
        REFERENCES clientes(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_servico
        FOREIGN KEY (servico_id)
        REFERENCES servicos(id)
        ON DELETE RESTRICT
);

-- TABELA: HORÁRIOS BLOQUEADOS

CREATE TABLE horarios_bloqueados (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    data DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fim TIME NOT NULL,

    motivo VARCHAR(120),

    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TABELA: TRAVAS DE AGENDA (evita double booking; ver executarComTravaDeAgenda)

CREATE TABLE agenda_locks (
    data DATE NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

-- TABELA: NOTIFICAÇÕES (fila e histórico de SMS — RF04)

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

-- TABELA: RECUPERAÇÃO DE SENHA

CREATE TABLE IF NOT EXISTS recuperacoes_senha (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    cliente_id BIGINT UNSIGNED NOT NULL,

    token VARCHAR(64) NOT NULL,
    expira_em DATETIME NOT NULL,
    usado BOOLEAN DEFAULT FALSE,

    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_recuperacao_cliente
        FOREIGN KEY (cliente_id)
        REFERENCES clientes(id)
        ON DELETE CASCADE
);

CREATE INDEX idx_recuperacao_token
ON recuperacoes_senha(token);


-- ÍNDICES

CREATE INDEX idx_agendamento_data
ON agendamentos(data);

CREATE INDEX idx_agendamento_status
ON agendamentos(status);

CREATE INDEX idx_cliente_blacklist
ON clientes(blacklist);

-- DADOS INICIAIS


-- Senha inicial: "123456" (já em hash bcrypt, compatível com password_verify() do PHP)
-- Troque essa senha assim que possível.
INSERT INTO administradores
(nome,email,senha)
VALUES
(
'Manicure',
'admin@maniagenda.com',
'$2b$12$1/jH7Vt2BibVXLoXn0EqY.nLZpT.6HhB6lxQsX.YsEhZ2ZM2mBjMi'
);

INSERT INTO servicos
(nome,descricao,preco,duracao)
VALUES
('Manicure Simples','Cuticulagem e esmaltação',40.00,60),

('Pedicure','Tratamento completo dos pés',50.00,70),

('Manicure + Pedicure','Pacote completo',85.00,120),

('Blindagem em Gel','Proteção das unhas',90.00,90),

('Alongamento em Fibra','Aplicação completa',180.00,180);