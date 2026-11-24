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

$_SESSION['token_mis_estudiantes'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['token_mis_estudiantes'];

$estudiantes = [];
$error = '';
$aviso = $_SESSION['aviso_mis_estudiantes'] ?? '';

unset($_SESSION['aviso_mis_estudiantes']);

try {
    $db = conectarBaseDatos();

    // Permite actualizar el nombre de un estudiante de sus cursos.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $tokenRecibido = $_POST['token'] ?? '';

        if (!is_string($tokenRecibido) || !hash_equals($token, $tokenRecibido)) {
            http_response_code(403);
            exit('Solicitud no válida.');
        }

        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $valor = $_POST['nombre'] ?? '';
        $nombre = is_string($valor) ? trim($valor) : '';

        if (!$id || $id < 1 || $nombre === '' || mb_strlen($nombre) > 100) {
            $error = 'Ingresa un nombre válido.';
        } else {

            $consulta = $db->prepare(
                "UPDATE usuarios u
                 JOIN estudiantes e ON e.usuario_id = u.id
                 JOIN matriculas m ON m.estudiante_id = e.id
                 JOIN cursos c ON c.id = m.curso_id
                 JOIN profesores p ON p.id = c.profesor_id
                 SET u.nombre = ?
                 WHERE e.id = ?
                 AND p.usuario_id = ?
                 AND m.estado = 'Aprobada'"
            );

            $consulta->execute([$nombre, $id, $usuario['id']]);

            $_SESSION['aviso_mis_estudiantes'] = $consulta->rowCount()
                ? 'Datos actualizados correctamente.'
                : 'No se realizaron cambios.';

            header('Location: mis_estudiantes.php', true, 303);
            exit;
        }
    }

    // Consulta únicamente estudiantes matriculados con el profesor.
    $consulta = $db->prepare(
        "SELECT e.id, u.nombre, u.correo,
         GROUP_CONCAT(DISTINCT c.nombre SEPARATOR ', ') AS cursos
         FROM estudiantes e
         JOIN usuarios u ON u.id = e.usuario_id
         JOIN matriculas m ON m.estudiante_id = e.id
         JOIN cursos c ON c.id = m.curso_id
         JOIN profesores p ON p.id = c.profesor_id
         WHERE p.usuario_id = ?
         AND m.estado = 'Aprobada'
         GROUP BY e.id, u.nombre, u.correo
         ORDER BY u.nombre"
    );

    $consulta->execute([$usuario['id']]);
    $estudiantes = $consulta->fetchAll();

} catch (PDOException $excepcion) {
    error_log($excepcion->getMessage());
    $error = 'No se pudo completar la operación.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis estudiantes | Gestión Escolar</title>

    <style>
        * {
            box-sizing: border-box;
        }

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

        .tarjeta {
            background: #e2f5ff;
            padding: 22px;
            margin-bottom: 18px;
            border-radius: 18px;
        }

        .tarjeta:nth-of-type(even) {
            background: #ffe5f0;
        }

        form {
            display: grid;
            gap: 10px;
        }

        input {
            padding: 12px;
            border: 1px solid #8bcfea;
            border-radius: 10px;
            font: inherit;
            width: 100%;
        }

        button {
            padding: 12px;
            background: #ffc9df;
            color: #5b2941;
            border: none;
            border-radius: 10px;
            font: inherit;
            font-weight: bold;
            cursor: pointer;
        }

        .aviso {
            background: #e2f5ff;
            padding: 12px;
            border-radius: 10px;
        }

        .error {
            background: #ffe5f0;
            padding: 12px;
            border-radius: 10px;
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

    <h1>🎓 Mis estudiantes</h1>

    <p>Profesor: <?= escapar($usuario['nombre']) ?></p>

    <?php if ($error !== ''): ?>
        <p class="error"><?= escapar($error) ?></p>
    <?php elseif ($aviso !== ''): ?>
        <p class="aviso"><?= escapar($aviso) ?></p>
    <?php endif; ?>

    <h2>Estudiantes matriculados</h2>

    <?php if (empty($estudiantes)): ?>
        <p>Todavía no tienes estudiantes matriculados.</p>
    <?php endif; ?>

    <?php foreach ($estudiantes as $estudiante): ?>

        <article class="tarjeta">

            <h3><?= escapar($estudiante['nombre']) ?></h3>

            <p>Correo: <?= escapar($estudiante['correo']) ?></p>

            <p>Curso: <?= escapar($estudiante['cursos']) ?></p>

            <form method="post" action="mis_estudiantes.php">

                <input type="hidden" name="token"
                       value="<?= escapar($token) ?>">

                <input type="hidden" name="id"
                       value="<?= (int) $estudiante['id'] ?>">

                <label for="nombre-<?= (int) $estudiante['id'] ?>">
                    Nombre completo
                </label>

                <input id="nombre-<?= (int) $estudiante['id'] ?>"
                       type="text" name="nombre"
                       value="<?= escapar($estudiante['nombre']) ?>"
                       maxlength="100" required>

                <button type="submit">Actualizar datos</button>

            </form>

        </article>

    <?php endforeach; ?>

    <footer>🌸 Hecho por Norka Mideros</footer>

</main>

</body>
</html>