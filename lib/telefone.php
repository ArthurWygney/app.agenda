<?php

/**
 * Padrão de telefone do sistema: (00) 00000-0000
 * (DDD com 2 dígitos + celular com 9 dígitos). É assim que o número é
 * exibido, gravado no banco (clientes.telefone) e comparado nas buscas.
 */

/** Remove tudo que não for dígito. */
function telefoneSomenteDigitos(string $telefone): string
{
    return preg_replace('/\D+/', '', $telefone) ?? '';
}

/**
 * Converte qualquer entrada ("11987654321", "(11)98765-4321", "+55 11 98765-4321")
 * para "(11) 98765-4321". Retorna null se não for um celular válido
 * (DDD de 11 a 99 + 9 dígitos começando com 9).
 */
function telefoneFormatar(string $telefone): ?string
{
    $d = telefoneSomenteDigitos($telefone);

    // Aceita o código do país (55) na frente
    if (strlen($d) === 13 && str_starts_with($d, '55')) {
        $d = substr($d, 2);
    }

    if (!preg_match('/^[1-9][1-9]9\d{8}$/', $d)) {
        return null;
    }

    return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7, 4));
}

/**
 * Se $identificador (campo "e-mail ou telefone") for um telefone válido,
 * devolve no formato padrão; caso contrário devolve o texto original.
 */
function telefoneOuOriginal(string $identificador): string
{
    if (str_contains($identificador, '@')) {
        return $identificador;
    }

    return telefoneFormatar($identificador) ?? $identificador;
}

/**
 * Formato internacional só com dígitos (55 + DDD + número), exigido pelos
 * provedores de SMS. Retorna '' se não for um celular brasileiro válido.
 */
function telefoneInternacional(string $telefone): string
{
    $formatado = telefoneFormatar($telefone);

    return $formatado === null ? '' : '55' . telefoneSomenteDigitos($formatado);
}
