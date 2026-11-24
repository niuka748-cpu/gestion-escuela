<?php
declare(strict_types=1);

session_start();

$usuario = $_SESSION['usuario'] ?? null;

if ($usuario === null) {
    header('Location: login.php');
    exit;
}

if ($usuario['rol'] !== 'Administrador') {
    http_response_code(403);
    exit('Solo el administrador puede gestionar profesores.');
}

require_once __DIR__ . '/conexion.php';

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

function leerTexto(string $campo): string
{
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? $valor : '';
}

$_SESSION['token_profesores'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['token_profesores'];

$aviso = $_SESSION['aviso_profesores'] ?? '';
unset($_SESSION['aviso_profesores']);

$error = '';
$id = 0;
$nombre = '';
$correo = '';
$profesores = [];

try {
    $baseDatos = conectarBaseDatos();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($token, leerTexto('token'))) {
            http_response_code(403);
            exit('Vuelve a abrir la página e inténtalo de nuevo.');
        }

        $accion = leerTexto('accion');
        $id = (int) filter_var(leerTexto('id'), FILTER_VALIDATE_INT);
        $nombre = trim(leerTexto('nombre'));
        $correo = trim(leerTexto('correo'));
        $clave = leerTexto('clave');

        $guardar = in_array($accion, ['crear', 'editar'], true);

        if (!$guardar && $accion !== 'eliminar') {
            throw new DomainException('La acción solicitada no es válida.');
        }

        if ($accion !== 'crear' && $id < 1) {
            throw new DomainException('Selecciona un profesor válido.');
        }

        if ($guardar && ($nombre === '' || mb_strlen($nombre) > 100
            || strlen($correo) > 150 || !filter_var($correo, FILTER_VALIDATE_EMAIL))) {
            throw new DomainException('Escribe un nombre y un correo válidos.');
        }

        if ($guardar && ($accion === 'crear' || $clave !== '')
            && (strlen($clave) < 8 || strlen($clave) > 72 || strpos($clave, "\0") !== false)) {
            throw new DomainException('Escribe una contraseña de entre 8 y 72 bytes.');
        }

        // Guarda la cuenta y el perfil juntos para evitar registros incompletos.
        $baseDatos->beginTransaction();

        if ($accion === 'crear') {
            $consulta = $baseDatos->prepare(
                'INSERT INTO usuarios (nombre, correo, contrasena_hash, rol_id)
                 VALUES (?, ?, ?, (SELECT id FROM roles WHERE nombre = ?))'
            );
            $consulta->execute([
                $nombre,
                $correo,
                password_hash($clave, PASSWORD_DEFAULT),
                'Profesor',
            ]);

            $usuarioId = (int) $baseDatos->lastInsertId();
            $consulta = $baseDatos->prepare('INSERT INTO profesores (usuario_id) VALUES (?)');
            $consulta->execute([$usuarioId]);
            $mensaje = 'Profesor agregado.';
        } else {
            $consulta = $baseDatos->prepare(
                'SELECT usuario_id FROM profesores WHERE id = ? FOR UPDATE'
            );
            $consulta->execute([$id]);
            $usuarioId = $consulta->fetchColumn();

            if ($usuarioId === false) {
                throw new DomainException('El profesor ya no existe.');
            }

            if ($accion === 'editar') {
                $consulta = $baseDatos->prepare(
                    'UPDATE usuarios SET nombre = ?, correo = ? WHERE id = ?'
                );
                $consulta->execute([$nombre, $correo, $usuarioId]);

                if ($clave !== '') {
                    $consulta = $baseDatos->prepare(
                        'UPDATE usuarios SET contrasena_hash = ? WHERE id = ?'
                    );
                    $consulta->execute([password_hash($clave, PASSWORD_DEFAULT), $usuarioId]);
                }

                $mensaje = 'Cambios guardados.';
            } else {
                $consulta = $baseDatos->prepare('DELETE FROM profesores WHERE id = ?');
                $consulta->execute([$id]);
                $consulta = $baseDatos->prepare('DELETE FROM usuarios WHERE id = ?');
                $consulta->execute([$usuarioId]);
                $mensaje = 'Profesor eliminado.';
            }
        }

        $baseDatos->commit();
        $_SESSION['aviso_profesores'] = $mensaje;
        header('Location: profesores.php', true, 303);
        exit;
    }

    $idEditar = filter_var($_GET['editar'] ?? null, FILTER_VALIDATE_INT);

    if ($idEditar > 0) {
        $consulta = $baseDatos->prepare(
            'SELECT p.id, u.nombre, u.correo
             FROM profesores p JOIN usuarios u ON u.id = p.usuario_id
             WHERE p.id = ?'
        );
        $consulta->execute([$idEditar]);
        $profesor = $consulta->fetch();

        if ($profesor) {
            $id = (int) $profesor['id'];
            $nombre = $profesor['nombre'];
            $correo = $profesor['correo'];
        } else {
            $error = 'El profesor ya no existe.';
        }
    }
} catch (DomainException | PDOException $excepcion) {
    if (isset($baseDatos) && $baseDatos->inTransaction()) {
        $baseDatos->rollBack();
    }

    if ($excepcion instanceof DomainException) {
        $error = $excepcion->getMessage();
    } else {
        $codigo = (int) ($excepcion->errorInfo[1] ?? 0);

        if ($codigo === 1062) {
            $error = 'Ese correo ya está registrado.';
        } elseif ($codigo === 1451) {
            $error = 'El profesor tiene cursos asociados. Reasígnalos antes de eliminarlo.';
        } else {
            error_log($excepcion->getMessage());
            $error = 'No se pudo completar la operación.';
        }
    }

    if (($_POST['accion'] ?? '') === 'eliminar') {
        $id = 0;
    }
}

// Consulta los profesores registrados junto con sus datos de acceso.
if (isset($baseDatos)) {
    try {
        $profesores = $baseDatos->query(
            'SELECT p.id, u.nombre, u.correo
             FROM profesores p JOIN usuarios u ON u.id = p.usuario_id
             ORDER BY u.nombre'
        )->fetchAll();
    } catch (PDOException $excepcion) {
        error_log($excepcion->getMessage());
        $error = 'No se pudo cargar la lista de profesores.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profesores | Gestión Escolar</title>
    <style>
        /* Colores celestes y rosados del panel escolar. */
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
            border-top: 6px solid #8bcfea;
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 18px 45px #294d6920;
        }
        a { color: #235675; }
        .formulario {
            display: grid;
            gap: 10px;
            padding: 20px;
            border-radius: 16px;
            background: #e2f5ff;
        }
        .formulario h2 { margin: 0; font-size: 1.2rem; }
        label { font-weight: 600; }
        input, button {
            padding: 10px 12px;
            border-radius: 10px;
            font: inherit;
        }
        input { min-width: 0; border: 1px solid #8bcfea; }
        button {
            border: 0;
            background: #ffc9df;
            color: #5b2941;
            font-weight: 700;
            cursor: pointer;
        }
        .mensaje { padding: 12px; border-radius: 10px; background: #e2f5ff; }
        .error { background: #ffe5f0; color: #742044; }
        ul { padding: 0; list-style: none; }
        li { padding: 16px 0; border-bottom: 1px solid #f0d9e5; overflow-wrap: anywhere; }
        .acciones { display: flex; align-items: center; gap: 14px; }
        footer { margin-top: 24px; text-align: center; color: #894368; }
    </style>
</head>
<body>
<main>
    <a href="panel.php">← Volver al panel</a>
    <h1>Profesores</h1>

    <?php if ($error !== ''): ?>
        <p class="mensaje error" role="alert"><?= escapar($error) ?></p>
    <?php elseif ($aviso !== ''): ?>
        <p class="mensaje" role="status"><?= escapar($aviso) ?></p>
    <?php endif; ?>

    <form class="formulario" method="post" action="profesores.php">
        <h2><?= $id > 0 ? 'Editar profesor' : 'Nuevo profesor' ?></h2>
        <input type="hidden" name="token" value="<?= escapar($token) ?>">
        <input type="hidden" name="accion" value="<?= $id > 0 ? 'editar' : 'crear' ?>">
        <input type="hidden" name="id" value="<?= $id ?>">

        <label for="nombre">Nombre completo</label>
        <input id="nombre" name="nombre" maxlength="100"
               value="<?= escapar($nombre) ?>" required>

        <label for="correo">Correo electrónico</label>
        <input id="correo" name="correo" type="email" maxlength="150"
               value="<?= escapar($correo) ?>" required>

        <label for="clave"><?= $id > 0 ? 'Nueva contraseña (opcional)' : 'Contraseña inicial' ?></label>
        <input id="clave" name="clave" type="password" autocomplete="new-password"
               minlength="8" maxlength="72" <?= $id === 0 ? 'required' : '' ?>>

        <button type="submit"><?= $id > 0 ? 'Guardar cambios' : 'Agregar profesor' ?></button>

        <?php if ($id > 0): ?>
            <a href="profesores.php">Cancelar edición</a>
        <?php endif; ?>
    </form>

    <h2>Profesores registrados</h2>

    <?php if ($profesores === [] && $error === ''): ?>
        <p>Todavía no hay profesores registrados.</p>
    <?php endif; ?>

    <ul>
        <?php foreach ($profesores as $profesor): ?>
            <li>
                <strong><?= escapar($profesor['nombre']) ?></strong>
                <p><?= escapar($profesor['correo']) ?></p>
                <div class="acciones">
                    <a href="profesores.php?editar=<?= (int) $profesor['id'] ?>">Editar</a>
                    <form class="form-eliminar" method="post" action="profesores.php">
                        <input type="hidden" name="token" value="<?= escapar($token) ?>">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id" value="<?= (int) $profesor['id'] ?>">
                        <button type="submit">Eliminar</button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <footer>Hecho por Norka Mideros</footer>
</main>
<script>
    // Confirma la eliminación antes de enviar el formulario.
    document.querySelectorAll('.form-eliminar').forEach(function (formulario) {
        formulario.addEventListener('submit', function (evento) {
            if (!window.confirm('¿Quieres eliminar al profesor y su cuenta?')) {
                evento.preventDefault();
            }
        });
    });
</script>
</body>
</html>