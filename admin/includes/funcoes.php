<?php

/**
 * Regras de agenda (ver documentação, seção 8 "Regras da Agenda").
 * Valores extraídos do exemplo prático do documento (expediente 08h-18h,
 * intervalo 12h-13h). Ainda não há tela de configuração destes horários
 * neste projeto — se a manicure tiver expediente diferente, ajuste aqui.
 */
const EXPEDIENTE_INICIO = '08:00:00';
const EXPEDIENTE_FIM    = '18:00:00';
const INTERVALO_INICIO  = '12:00:00';
const INTERVALO_FIM     = '13:00:00';

/**
 * Regra de blacklist automática (RN03). O documento define que clientes
 * com "faltas recorrentes" devem ser bloqueadas, mas não define o número
 * exato de faltas consecutivas necessárias (assim como a RN04, que segue
 * incompleta). Foi assumido o valor 3 como padrão provisório — ajustável
 * aqui até que esse número seja confirmado com a manicure.
 */
const LIMITE_FALTAS_BLACKLIST = 3;

const STATUS_LABELS = [
    'agendado'       => 'Agendado',
    'confirmado'     => 'Confirmado',
    'concluido'      => 'Concluído',
    'nao_compareceu' => 'Não compareceu',
    'cancelado'      => 'Cancelado',
];

const STATUS_ATIVOS = ['agendado', 'confirmado'];

function formatarData(string $data): string
{
    $partes = explode('-', $data);
    if (count($partes) !== 3) {
        return $data;
    }
    return $partes[2] . '/' . $partes[1] . '/' . $partes[0];
}

function formatarHora(string $hora): string
{
    return substr($hora, 0, 5);
}

function formatarMoeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function classeStatus(string $status): string
{
    $mapa = [
        'agendado'       => 'status-agendado',
        'confirmado'     => 'status-confirmado',
        'concluido'      => 'status-concluido',
        'nao_compareceu' => 'status-faltou',
        'cancelado'      => 'status-cancelado',
    ];

    return $mapa[$status] ?? '';
}

/**
 * Executa $acao dentro de uma transação protegida por uma trava exclusiva
 * do dia ($data). Serve para evitar double booking: duas requisições
 * simultâneas para o mesmo dia ficam em fila — a segunda só roda a
 * verificação de conflito DEPOIS que a primeira deu COMMIT, e então
 * enxerga o agendamento recém-criado.
 *
 * Como a manicure atende uma cliente por vez, a agenda é um único
 * "recurso" e a trava por dia é suficiente. Se um dia passar a ter
 * mais de uma profissional, a trava deve ser por (profissional, dia).
 *
 * Exige tabela `agenda_locks` (ver config/migracao_anti_double_booking.sql).
 * Se $acao lançar exceção, faz ROLLBACK e relança.
 *
 * @template T
 * @param callable(): T $acao
 * @return T
 */
function executarComTravaDeAgenda(PDO $pdo, string $data, callable $acao)
{
    $pdo->beginTransaction();

    try {

        // Garante que a linha do dia exista (idempotente, sem erro se já existe)
        $stmt = $pdo->prepare('INSERT IGNORE INTO agenda_locks (data) VALUES (:data)');
        $stmt->execute([':data' => $data]);

        // Trava exclusiva da linha do dia: quem chegar depois espera aqui
        $stmt = $pdo->prepare('SELECT data FROM agenda_locks WHERE data = :data FOR UPDATE');
        $stmt->execute([':data' => $data]);

        $resultado = $acao();

        $pdo->commit();

        return $resultado;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Verifica se um intervalo [horaInicio, horaFim) em uma data respeita o
 * expediente, não cai sobre o intervalo de almoço, não colide com outro
 * agendamento ativo e não colide com um horário bloqueado manualmente.
 *
 * Retorna string vazia se estiver tudo certo, ou uma mensagem de erro
 * explicando o motivo do conflito.
 */
function verificarConflitoAgenda(
    PDO $pdo,
    string $data,
    string $horaInicio,
    string $horaFim,
    ?int $agendamentoIdExcluir = null
): string {

    if ($horaFim <= $horaInicio) {
        return 'Horário inválido.';
    }

    if ($horaInicio < EXPEDIENTE_INICIO || $horaFim > EXPEDIENTE_FIM) {
        return 'Fora do horário de expediente (' .
            formatarHora(EXPEDIENTE_INICIO) . ' às ' . formatarHora(EXPEDIENTE_FIM) . ').';
    }

    // Sobreposição com o intervalo de almoço
    if ($horaInicio < INTERVALO_FIM && $horaFim > INTERVALO_INICIO) {
        return 'O horário cai dentro do intervalo (' .
            formatarHora(INTERVALO_INICIO) . ' às ' . formatarHora(INTERVALO_FIM) . ').';
    }

    // Sobreposição com outros agendamentos ativos (agendado/confirmado)
    $sql = "SELECT a.id, c.nome AS cliente_nome, a.hora_inicio, a.hora_fim
            FROM agendamentos a
            JOIN clientes c ON c.id = a.cliente_id
            WHERE a.data = :data
              AND a.status IN ('agendado', 'confirmado')
              AND a.hora_inicio < :hora_fim
              AND a.hora_fim > :hora_inicio";

    $params = [
        ':data'        => $data,
        ':hora_inicio' => $horaInicio,
        ':hora_fim'    => $horaFim,
    ];

    if ($agendamentoIdExcluir !== null) {
        $sql .= ' AND a.id <> :id_excluir';
        $params[':id_excluir'] = $agendamentoIdExcluir;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $conflito = $stmt->fetch();

    if ($conflito) {
        return 'Já existe um agendamento de ' . htmlspecialchars($conflito['cliente_nome']) .
            ' das ' . formatarHora($conflito['hora_inicio']) . ' às ' . formatarHora($conflito['hora_fim']) . '.';
    }

    // Sobreposição com horário bloqueado manualmente
    $sql = "SELECT motivo, hora_inicio, hora_fim
            FROM horarios_bloqueados
            WHERE data = :data
              AND hora_inicio < :hora_fim
              AND hora_fim > :hora_inicio";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':data'        => $data,
        ':hora_inicio' => $horaInicio,
        ':hora_fim'    => $horaFim,
    ]);
    $bloqueio = $stmt->fetch();

    if ($bloqueio) {
        $motivo = $bloqueio['motivo'] ? ' (' . htmlspecialchars($bloqueio['motivo']) . ')' : '';
        return 'Horário bloqueado das ' . formatarHora($bloqueio['hora_inicio']) .
            ' às ' . formatarHora($bloqueio['hora_fim']) . $motivo . '.';
    }

    return '';
}

/**
 * Gera a lista de horários de início livres em uma data, para um serviço
 * de determinada duração — usada pela tela de agendamento da cliente.
 *
 * Percorre o expediente em passos de $passoMinutos e reaproveita
 * verificarConflitoAgenda() para descartar horários que caem no
 * intervalo, colidem com outro agendamento ativo ou com um bloqueio
 * manual. Se a data for hoje, descarta horários que já passaram.
 *
 * Retorna um array de strings 'HH:MM'.
 */
function gerarHorariosDisponiveis(
    PDO $pdo,
    string $data,
    int $duracaoMinutos,
    int $passoMinutos = 30,
    ?int $agendamentoIdExcluir = null
): array {

    $horarios = [];

    $horaAtual  = strtotime($data . ' ' . EXPEDIENTE_INICIO);
    $horaLimite = strtotime($data . ' ' . EXPEDIENTE_FIM);
    $agora      = time();

    while ($horaAtual + ($duracaoMinutos * 60) <= $horaLimite) {

        $horaInicio = date('H:i:s', $horaAtual);
        $horaFim    = date('H:i:s', $horaAtual + ($duracaoMinutos * 60));

        if ($horaAtual > $agora) {

            $conflito = verificarConflitoAgenda($pdo, $data, $horaInicio, $horaFim, $agendamentoIdExcluir);

            if ($conflito === '') {
                $horarios[] = formatarHora($horaInicio);
            }
        }

        $horaAtual += $passoMinutos * 60;
    }

    return $horarios;
}
