<?php
declare(strict_types=1);

session_start();

$usuario = $_SESSION['usuario'] ?? null;

if (!$usuario) {
    header('Location: login.php');
    exit;
}

if ($usuario['rol'] !== 'Profesor') {
    http_response_code(403);
    exit('Acceso exclusivo para profesores.');
}

require_once __DIR__ . '/conexion.php';

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

$cursos = [];
$error = '';

try {
    $db = conectarBaseDatos();

    // Consulta únicamente los cursos asignados al profesor.
    $consulta = $db->prepare(
        'SELECT c.*, a.nombre AS asignatura
         FROM cursos c
         JOIN profesores p ON p.id = c.profesor_id
         JOIN asignaturas a ON a.id = c.asignatura_id
         WHERE p.usuario_id = ?
         ORDER BY c.nombre'
    );

    $consulta->execute([$usuario['id']]);
    $cursos = $consulta->fetchAll();

} catch (PDOException $excepcion) {
    error_log($excepcion->getMessage());
    $error = 'No se pudieron consultar los cursos.';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis cursos | Gestión Escolar</title>

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            padding: 30px 16px;
            background: linear-gradient(135deg, #d9f3ff, #fff0f6);
            font-family: "Segoe UI", Arial, sans-serif;
            color: #243850;
        }

        main {
            max-width: 850px;
            margin: auto;
            padding: 35px;
            background: white;
            border-top: 6px solid #8bcfea;
            border-radius: 24px;
            box-shadow: 0 18px 45px #294d6920;
        }

        a {
            color: #235675;
        }

        .curso {
            background: #e2f5ff;
            padding: 22px;
            margin-bottom: 18px;
            border-radius: 18px;
        }

        .curso:nth-of-type(even) {
            background: #ffe5f0;
        }

        .boton {
            display: inline-block;
            background: #ffc9df;
            padding: 10px 18px;
            border-radius: 10px;
            text-decoration: none;
            color: #5b2941;
            font-weight: bold;
        }

        .error {
            color: #742044;
        }

        footer {
            margin-top: 30px;
            text-align: center;
            color: #894368;
        }
    </style>
</head>

<body>

<main>
    <a href="panel.php">← Volver al panel</a>

    <h1>📚 Mis cursos</h1>

    <p>Profesor: <?= escapar($usuario['nombre']) ?></p>

    <?php if ($error !== ''): ?>

        <p class="error"><?= escapar($error) ?></p>

    <?php elseif (empty($cursos)): ?>

        <p>Todavía no tienes cursos asignados.</p>

    <?php endif; ?>

    <?php foreach ($cursos as $curso): ?>

        <article class="curso">

            <h2><?= escapar($curso['nombre']) ?></h2>

            <p><strong>Asignatura:</strong>
                <?= escapar($curso['asignatura']) ?>
            </p>

            <p><?= escapar($curso['descripcion']) ?></p>

            <p><strong>Inicio:</strong>
                <?= escapar($curso['fecha_inicio']) ?>
            </p>

            <p><strong>Finalización:</strong>
                <?= escapar($curso['fecha_fin']) ?>
            </p>

            <a class="boton" href="calificaciones.php">
                Gestionar calificaciones
            </a>

        </article>

    <?php endforeach; ?>

    <footer>🌸 Hecho por Norka Mideros</footer>

</main>

</body>
</html>