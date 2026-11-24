<?php
// Datos de conexión de Gestión Escolar.
function conectarBaseDatos(): PDO
{
    $servidor = '127.0.0.1';
    $puerto = 3307;
    $baseDatos = 'gestion_escuela';
    $usuario = 'root';
    $contrasena = '';

    $dsn = "mysql:host=$servidor;port=$puerto;dbname=$baseDatos;charset=utf8mb4";

    return new PDO($dsn, $usuario, $contrasena, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}