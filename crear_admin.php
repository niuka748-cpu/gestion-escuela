<?php
declare(strict_types=1);

// La cuenta inicial se crea desde la consola de XAMPP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/conexion.php';

$correo = 'admin@escuela.test';

try {
    $baseDatos = conectarBaseDatos();

    $consulta = $baseDatos->prepare('SELECT id FROM usuarios WHERE correo = ?');
    $consulta->execute([$correo]);

    if ($consulta->fetchColumn() !== false) {
        exit("La cuenta $correo ya existe. Usa la clave que se mostro al crearla.\n");
    }

    $consulta = $baseDatos->prepare('SELECT id FROM roles WHERE nombre = ?');
    $consulta->execute(['Administrador']);
    $rolId = $consulta->fetchColumn();

    if ($rolId === false) {
        exit("Falta el rol Administrador en la base de datos.\n");
    }

    // Genera una clave y guarda su hash en la base.
    $clave = bin2hex(random_bytes(8));
    $consulta = $baseDatos->prepare(
        'INSERT INTO usuarios (nombre, correo, contrasena_hash, rol_id)
         VALUES (?, ?, ?, ?)'
    );
    $consulta->execute([
        'Norka Mideros',
        $correo,
        password_hash($clave, PASSWORD_DEFAULT),
        $rolId,
    ]);

    echo "Cuenta creada.\nCorreo: $correo\nClave: $clave\n";
} catch (PDOException $error) {
    fwrite(STDERR, "Error de base de datos: " . $error->getMessage() . "\n");
    exit(1);
}