<?php
require_once '../config/database.php';

$sql = "SELECT * FROM servicos WHERE ativo = 1";
$stmt = $pdo->query($sql);

$servicos = $stmt->fetchAll();