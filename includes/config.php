<?php
// Configurações do banco de dados
$host = 'localhost';
$dbname = 'crm'; // Alterado para 'crm'
$username = 'root'; // Usuário padrão do XAMPP (mude se necessário)
$password = '1234';     // Senha padrão do XAMPP (geralmente vazia em localhost)

try {
    // Conexão com PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4");
} catch (PDOException $e) {
    // Em caso de erro, exibe mensagem (em produção, logar o erro)
    die("Erro na conexão: " . $e->getMessage());
}
?>