<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/conexion.php';

$db = conectarBaseDatos();

$cuentas = [
    ['Administrador Demo', 'admin@demo.test', 'Administrador'],
    ['Profesor Demo', 'profesor@demo.test', 'Profesor'],
    ['Estudiante Demo', 'estudiante@demo.test', 'Estudiante']
];

try {
    $db->beginTransaction();

    foreach ($cuentas as [$nombre, $correo, $rol]) {
        $consulta = $db->prepare('SELECT id FROM usuarios WHERE correo = ?');
        $consulta->execute([$correo]);

        if ($consulta->fetchColumn() !== false) {
            continue;
        }

        $consulta = $db->prepare(
            'INSERT INTO usuarios (nombre, correo, contrasena_hash, rol_id)
             VALUES (?, ?, ?, (SELECT id FROM roles WHERE nombre = ?))'
        );

        $consulta->execute([
            $nombre,
            $correo,
            password_hash('EscolarDemo2026', PASSWORD_DEFAULT),
            $rol
        ]);

        $id = (int) $db->lastInsertId();

        if ($rol === 'Profesor') {
            $db->prepare('INSERT INTO profesores (usuario_id) VALUES (?)')
                ->execute([$id]);
        }

        if ($rol === 'Estudiante') {
            $db->prepare('INSERT INTO estudiantes (usuario_id) VALUES (?)')
                ->execute([$id]);
        }
    }

    $db->commit();
    echo "Cuentas de demostracion creadas correctamente.";

} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    echo "Error: " . $error->getMessage();
}