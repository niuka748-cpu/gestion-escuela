<?php
declare(strict_types=1);

session_start();

// Solo el administrador puede gestionar las asignaturas.
$usuario = $_SESSION['usuario'] ?? null;

if ($usuario === null) {
    header('Location: login.php');
    exit;
}

if ($usuario['rol'] !== 'Administrador') {
    http_response_code(403);
    exit('Solo el administrador puede gestionar las asignaturas.');
}

require_once __DIR__ . '/conexion.php';

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

function leerId($valor): int
{
    return filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]) ?: 0;
}

$_SESSION['token_asignaturas'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['token_asignaturas'];

$error = '';
$aviso = $_SESSION['aviso_asignaturas'] ?? '';
unset($_SESSION['aviso_asignaturas']);

$id = 0;
$nombre = '';
$descripcion = '';
$asignaturas = [];

try {
    $baseDatos = conectarBaseDatos();

    // Carga la asignatura elegida al pulsar Editar.
    $idEditar = leerId($_GET['editar'] ?? null);

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $idEditar > 0) {
        $consulta = $baseDatos->prepare(
            'SELECT id, nombre, descripcion FROM asignaturas WHERE id = ?'
        );
        $consulta->execute([$idEditar]);
        $asignatura = $consulta->fetch();

        if ($asignatura) {
            $id = (int) $asignatura['id'];
            $nombre = $asignatura['nombre'];
            $descripcion = $asignatura['descripcion'];
        } else {
            $error = 'La asignatura ya no existe.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tokenRecibido = $_POST['token'] ?? '';

        if (!is_string($tokenRecibido) || !hash_equals($token, $tokenRecibido)) {
            http_response_code(403);
            exit('Vuelve a abrir la página de asignaturas e inténtalo otra vez.');
        }

        $accion = $_POST['accion'] ?? '';
        $idRecibido = leerId($_POST['id'] ?? null);

        try {
            // Usa consultas preparadas para guardar y eliminar registros.
            if ($accion === 'eliminar' && $idRecibido > 0) {
                $consulta = $baseDatos->prepare('DELETE FROM asignaturas WHERE id = ?');
                $consulta->execute([$idRecibido]);

                $_SESSION['aviso_asignaturas'] = $consulta->rowCount() > 0
                    ? 'Asignatura eliminada.'
                    : 'La asignatura ya no existe.';
            } elseif ($accion === 'crear' || ($accion === 'editar' && $idRecibido > 0)) {
                $id = $accion === 'editar' ? $idRecibido : 0;
                $nombre = trim(is_string($_POST['nombre'] ?? null) ? $_POST['nombre'] : '');
                $descripcion = trim(is_string($_POST['descripcion'] ?? null) ? $_POST['descripcion'] : '');

                if ($nombre === '' || $descripcion === ''
                    || mb_strlen($nombre) > 100 || mb_strlen($descripcion) > 255) {
                    $error = 'Escribe un nombre de hasta 100 caracteres y una descripción de hasta 255.';
                } elseif ($id > 0) {
                    $consulta = $baseDatos->prepare(
                        'UPDATE asignaturas SET nombre = ?, descripcion = ? WHERE id = ?'
                    );
                    $consulta->execute([$nombre, $descripcion, $id]);
                    $_SESSION['aviso_asignaturas'] = 'Cambios guardados.';
                } else {
                    $consulta = $baseDatos->prepare(
                        'INSERT INTO asignaturas (nombre, descripcion) VALUES (?, ?)'
                    );
                    $consulta->execute([$nombre, $descripcion]);
                    $_SESSION['aviso_asignaturas'] = 'Asignatura agregada.';
                }
            } else {
                $error = 'La acción solicitada no es válida.';
            }
        } catch (PDOException $excepcion) {
            $codigo = (int) ($excepcion->errorInfo[1] ?? 0);

            if ($codigo === 1062) {
                $error = 'Ya existe una asignatura con ese nombre.';
            } elseif ($codigo === 1451) {
                $error = 'Esta asignatura tiene cursos asociados.';
            } else {
                throw $excepcion;
            }
        }

        // Evita repetir una operación si se recarga la página.
        if ($error === '') {
            header('Location: asignaturas.php', true, 303);
            exit;
        }
    }

    $asignaturas = $baseDatos->query(
        'SELECT id, nombre, descripcion FROM asignaturas ORDER BY nombre'
    )->fetchAll();
} catch (PDOException $excepcion) {
    error_log($excepcion->getMessage());
    $error = 'No se pudieron cargar o guardar las asignaturas.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asignaturas | Gestión Escolar</title>
    <style>
        /* Mantiene los colores del panel de administración. */
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
            background: white;
            border-top: 6px solid #8bcfea;
            border-radius: 24px;
            box-shadow: 0 18px 45px #294d6920;
        }

        a { color: #235675; }

        .formulario {
            display: grid;
            gap: 10px;
            padding: 20px;
            background: #e2f5ff;
            border-radius: 16px;
        }

        .formulario h2 { margin: 0; font-size: 1.2rem; }
        label { font-weight: 600; }

        input, textarea, button {
            padding: 10px 12px;
            border-radius: 10px;
            font: inherit;
        }

        input, textarea {
            width: 100%;
            border: 1px solid #8bcfea;
        }

        button {
            border: 0;
            background: #ffc9df;
            color: #5b2941;
            font-weight: 700;
            cursor: pointer;
        }

        .mensaje { padding: 12px; border-radius: 10px; background: #e2f5ff; }
        .error { background: #ffe5f0; color: #742044; }
        .lista { padding: 0; list-style: none; }
        .lista li { padding: 16px 0; border-bottom: 1px solid #f0d9e5; }
        .lista p { overflow-wrap: anywhere; }
        .acciones { display: flex; align-items: center; gap: 14px; }
        footer { margin-top: 24px; text-align: center; color: #894368; }
    </style>
</head>
<body>
    <main>
        <a href="panel.php">← Volver al panel</a>
        <h1>Asignaturas</h1>

        <?php if ($error !== ''): ?>
            <p class="mensaje error" role="alert"><?= escapar($error) ?></p>
        <?php elseif ($aviso !== ''): ?>
            <p class="mensaje" role="status"><?= escapar($aviso) ?></p>
        <?php endif; ?>

        <form class="formulario" method="post" action="asignaturas.php">
            <h2><?= $id > 0 ? 'Editar asignatura' : 'Nueva asignatura' ?></h2>
            <input type="hidden" name="token" value="<?= escapar($token) ?>">
            <input type="hidden" name="accion" value="<?= $id > 0 ? 'editar' : 'crear' ?>">
            <input type="hidden" name="id" value="<?= $id ?>">

            <label for="nombre">Nombre</label>
            <input id="nombre" name="nombre" maxlength="100"
                   value="<?= escapar($nombre) ?>" required>

            <label for="descripcion">Descripción</label>
            <textarea id="descripcion" name="descripcion" rows="3"
                      maxlength="255" required><?= escapar($descripcion) ?></textarea>

            <button type="submit"><?= $id > 0 ? 'Guardar cambios' : 'Agregar asignatura' ?></button>

            <?php if ($id > 0): ?>
                <a href="asignaturas.php">Cancelar edición</a>
            <?php endif; ?>
        </form>

        <h2>Asignaturas registradas</h2>

        <?php if ($asignaturas === [] && $error === ''): ?>
            <p>Todavía no hay asignaturas registradas.</p>
        <?php endif; ?>

        <ul class="lista">
            <?php foreach ($asignaturas as $asignatura): ?>
                <li>
                    <strong><?= escapar($asignatura['nombre']) ?></strong>
                    <p><?= escapar($asignatura['descripcion']) ?></p>

                    <div class="acciones">
                        <a href="asignaturas.php?editar=<?= (int) $asignatura['id'] ?>">Editar</a>

                        <form class="form-eliminar" method="post" action="asignaturas.php">
                            <input type="hidden" name="token" value="<?= escapar($token) ?>">
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id" value="<?= (int) $asignatura['id'] ?>">
                            <button type="submit">Eliminar</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>

        <footer>Hecho por Norka Mideros</footer>
    </main>

    <script>
        // Pide confirmación antes de enviar una eliminación.
        document.querySelectorAll('.form-eliminar').forEach(function (formulario) {
            formulario.addEventListener('submit', function (evento) {
                if (!window.confirm('¿Quieres eliminar esta asignatura?')) {
                    evento.preventDefault();
                }
            });
        });
    </script>
</body>
</html>