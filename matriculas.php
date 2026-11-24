<?php
declare(strict_types=1);

session_start();

$usuario = $_SESSION['usuario'] ?? null;

if ($usuario === null) {
    header('Location: login.php');
    exit;
}

$esAdmin = $usuario['rol'] === 'Administrador';

if (!$esAdmin && $usuario['rol'] !== 'Estudiante') {
    http_response_code(403);
    exit('Esta pantalla es para administradores y estudiantes.');
}

require_once __DIR__ . '/conexion.php';

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

$_SESSION['token_matriculas'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['token_matriculas'];
$aviso = $_SESSION['aviso_matriculas'] ?? '';
unset($_SESSION['aviso_matriculas']);

$error = '';
$cursos = [];
$matriculas = [];
$estudianteId = 0;

try {
    $db = conectarBaseDatos();

    // Identifica al estudiante por su sesión, no por datos del formulario.
    if (!$esAdmin) {
        $consulta = $db->prepare('SELECT id FROM estudiantes WHERE usuario_id = ?');
        $consulta->execute([$usuario['id']]);
        $estudianteId = (int) $consulta->fetchColumn();

        if ($estudianteId === 0) {
            http_response_code(403);
            exit('Tu cuenta ya no tiene un perfil de estudiante activo.');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tokenRecibido = $_POST['token'] ?? '';

        if (!is_string($tokenRecibido) || !hash_equals($token, $tokenRecibido)) {
            http_response_code(403);
            exit('Vuelve a abrir la pantalla de matrículas.');
        }

        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $accion = $_POST['accion'] ?? '';

        try {
            if ($id === false) {
                throw new DomainException('Selecciona un registro válido.');
            }

            if (!$esAdmin && $accion === 'solicitar') {
                $consulta = $db->prepare(
                    'INSERT INTO matriculas (estudiante_id, curso_id) VALUES (?, ?)'
                );
                $consulta->execute([$estudianteId, $id]);
                $mensaje = 'Solicitud enviada. Quedó pendiente de revisión.';
            } elseif ($esAdmin && in_array($accion, ['Aprobada', 'Rechazada'], true)) {
                // Solo se puede tomar una decisión sobre una solicitud pendiente.
                $consulta = $db->prepare(
                    "UPDATE matriculas SET estado = ? WHERE id = ? AND estado = 'Pendiente'"
                );
                $consulta->execute([$accion, $id]);
                $mensaje = $consulta->rowCount() > 0
                    ? 'Estado actualizado.'
                    : 'La solicitud ya fue revisada o ya no existe.';
            } else {
                throw new DomainException('Esta acción no está disponible para tu perfil.');
            }

            $_SESSION['aviso_matriculas'] = $mensaje;
            header('Location: matriculas.php', true, 303);
            exit;
        } catch (DomainException $excepcion) {
            $error = $excepcion->getMessage();
        } catch (PDOException $excepcion) {
            $codigo = (int) ($excepcion->errorInfo[1] ?? 0);

            if ($codigo === 1062) {
                $error = 'Ya tienes una solicitud para ese curso.';
            } elseif ($codigo === 1452) {
                $error = 'El curso seleccionado ya no está disponible.';
            } else {
                throw $excepcion;
            }
        }
    }

    // El administrador ve todas las solicitudes. Cada estudiante ve las suyas.
    $sql = 'SELECT m.id, m.estado, c.nombre AS curso, u.nombre AS estudiante
            FROM matriculas m
            JOIN cursos c ON c.id = m.curso_id
            JOIN estudiantes e ON e.id = m.estudiante_id
            JOIN usuarios u ON u.id = e.usuario_id';

    if ($esAdmin) {
        $consulta = $db->query($sql . ' ORDER BY m.id DESC');
    } else {
        $consulta = $db->prepare($sql . ' WHERE m.estudiante_id = ? ORDER BY m.id DESC');
        $consulta->execute([$estudianteId]);
    }

    $matriculas = $consulta->fetchAll();

    if (!$esAdmin) {
        $consulta = $db->prepare(
            'SELECT c.id, c.nombre
             FROM cursos c
             WHERE NOT EXISTS (
                 SELECT 1 FROM matriculas m
                 WHERE m.curso_id = c.id AND m.estudiante_id = ?
             )
             ORDER BY c.nombre'
        );
        $consulta->execute([$estudianteId]);
        $cursos = $consulta->fetchAll();
    }
} catch (PDOException $excepcion) {
    error_log($excepcion->getMessage());
    $error = 'No se pudieron cargar o guardar las matrículas.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Matrículas | Gestión Escolar</title>
    <style>
        /* Colores celestes y rosados del sistema escolar. */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 28px 16px;
            background: linear-gradient(135deg, #d9f3ff, #fff0f6);
            color: #243850;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        main {
            max-width: 850px;
            margin: auto;
            padding: clamp(20px, 5vw, 40px);
            background: #fff;
            border-top: 6px solid #8bcfea;
            border-radius: 24px;
            box-shadow: 0 18px 45px #294d6920;
        }

        a { color: #235675; }

        .solicitud {
            display: grid;
            gap: 12px;
            padding: 20px;
            border-radius: 16px;
            background: #e2f5ff;
        }

        select, button {
            min-width: 0;
            padding: 10px 12px;
            border-radius: 10px;
            font: inherit;
        }

        select { border: 1px solid #8bcfea; }

        button {
            border: 0;
            background: #ffc9df;
            color: #5b2941;
            font-weight: 700;
            cursor: pointer;
        }

        .acciones { display: flex; flex-wrap: wrap; gap: 10px; }
        .acciones button:first-of-type { background: #e2f5ff; }

        article {
            padding: 16px 0;
            border-bottom: 1px solid #f0d9e5;
        }

        article h3 { margin: 0; }
        .mensaje { padding: 12px; border-radius: 10px; background: #e2f5ff; }
        .error { background: #ffe5f0; color: #742044; }

        footer {
            margin-top: 24px;
            text-align: center;
            color: #894368;
        }
    </style>
</head>
<body>
<main>
    <?php if ($esAdmin): ?>
        <a href="panel.php">← Volver al panel</a>
    <?php else: ?>
        <a href="login.php">← Volver a mi cuenta</a>
    <?php endif; ?>

    <h1>Matrículas</h1>

    <?php if ($error !== ''): ?>
        <p class="mensaje error" role="alert"><?= escapar($error) ?></p>
    <?php elseif ($aviso !== ''): ?>
        <p class="mensaje" role="status"><?= escapar($aviso) ?></p>
    <?php endif; ?>

    <?php if (!$esAdmin && $cursos !== []): ?>
        <form class="solicitud" method="post" action="matriculas.php">
            <input type="hidden" name="token" value="<?= escapar($token) ?>">
            <input type="hidden" name="accion" value="solicitar">

            <label for="curso">Curso que deseas solicitar</label>
            <select id="curso" name="id" required>
                <option value="">Selecciona un curso</option>
                <?php foreach ($cursos as $curso): ?>
                    <option value="<?= (int) $curso['id'] ?>">
                        <?= escapar($curso['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Solicitar matrícula</button>
        </form>
    <?php elseif (!$esAdmin && $error === ''): ?>
        <p>No tienes cursos nuevos para solicitar.</p>
    <?php endif; ?>

    <h2><?= $esAdmin ? 'Solicitudes recibidas' : 'Mis solicitudes' ?></h2>

    <?php if ($matriculas === [] && $error === ''): ?>
        <p>Todavía no hay solicitudes.</p>
    <?php endif; ?>

    <?php foreach ($matriculas as $matricula): ?>
        <article>
            <h3><?= escapar($matricula['curso']) ?></h3>

            <?php if ($esAdmin): ?>
                <p>Estudiante: <?= escapar($matricula['estudiante']) ?></p>
            <?php endif; ?>

            <p>Estado: <strong><?= escapar($matricula['estado']) ?></strong></p>

            <?php if ($esAdmin && $matricula['estado'] === 'Pendiente'): ?>
                <form class="acciones" method="post" action="matriculas.php">
                    <input type="hidden" name="token" value="<?= escapar($token) ?>">
                    <input type="hidden" name="id" value="<?= (int) $matricula['id'] ?>">
                    <button name="accion" value="Aprobada">Aprobar</button>
                    <button name="accion" value="Rechazada">Rechazar</button>
                </form>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    <footer>Hecho por Norka Mideros</footer>
</main>
</body>
</html>