<?php

/**
 * A área do cliente reaproveita as constantes de expediente e as
 * funções de formatação/conflito já definidas para o admin, para que
 * as duas áreas nunca fiquem com regras de agenda divergentes.
 */
require_once __DIR__ . '/../../admin/includes/funcoes.php';

/**
 * RN02 — Limite de cancelamento: a cliente só pode cancelar ou remarcar
 * pelo sistema com até 24h de antecedência. Abaixo disso, deve falar
 * diretamente com a manicure.
 */
const LIMITE_ALTERACAO_HORAS = 24;

function podeAlterarAgendamento(string $data, string $horaInicio): bool
{
    $inicio = strtotime($data . ' ' . $horaInicio);

    return $inicio !== false
        && ($inicio - time()) >= LIMITE_ALTERACAO_HORAS * 3600;
}
