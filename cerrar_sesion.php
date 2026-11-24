<?php
session_start();

// Verifica que la solicitud proviene del formulario del panel.
$token = $_POST['token'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !is_string($token)
    || !isset($_SESSION['token_logout'])
    || !hash_equals($_SESSION['token_logout'], $token)) {
    http_response_code(403);
    exit('Solicitud no válida.');
}

// Finaliza la sesión del usuario.
$_SESSION = [];
session_destroy();

header('Location: login.php', true, 303);
exit;