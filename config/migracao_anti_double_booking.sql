-- MIGRAÇÃO: proteção contra double booking
-- Rode uma vez em bancos já existentes (em instalações novas, já está no database.sql).

USE maniagenda;

CREATE TABLE IF NOT EXISTS agenda_locks (
    data DATE NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

-- agendamentos precisa ser InnoDB (necessário para transações e SELECT ... FOR UPDATE).
-- Confira com: SHOW TABLE STATUS WHERE Name = 'agendamentos';
-- Se estiver MyISAM, rode: ALTER TABLE agendamentos ENGINE=InnoDB;
