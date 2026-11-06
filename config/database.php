<?php

// Fuso horário da aplicação: a regra de 24h (RN02) e os lembretes dependem dele.
date_default_timezone_set('America/Sao_Paulo');

$host = 'localhost';
$db   = 'maniagenda';
$user = 'root';
$pass = '';

try {

    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {

    die('Erro na conexão com o banco de dados: ' . $e->getMessage());

}

