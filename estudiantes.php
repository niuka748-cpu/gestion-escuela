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
    exit('Solo el administrador puede gestionar estudiantes.');
}

require_once __DIR__ . '/conexion.php';

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

function textoEnviado(string $campo): string
{
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? $valor : '';
}

$_SESSION['token_estudiantes'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['token_estudiantes'];
$aviso = $_SESSION['aviso_estudiantes'] ?? '';
unset($_SESSION['aviso_estudiantes']);

$error = '';
$id = 0;
$nombre = '';
$correo = '';
$estudiantes = [];

try {
    $db = conectarBaseDatos();

    // Carga los registros para la lista y el formulario de edición.
    $estudiantes = $db->query(
        'SELECT e.id, u.nombre, u.correo
         FROM estudiantes e
         JOIN usuarios u ON u.id = e.usuario_id
         ORDER BY u.nombre, e.id'
    )->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($token, textoEnviado('token'))) {
            http_response_code(403);
            exit('Vuelve a abrir la página e inténtalo de nuevo.');
        }

        $accion = textoEnviado('accion');
        $idRecibido = filter_var(textoEnviado('id'), FILTER_VALIDATE_INT);
        $nombre = trim(textoEnviado('nombre'));
        $correo = trim(textoEnviado('correo'));
        $clave = textoEnviado('clave');

        if (!in_array($accion, ['crear', 'editar', 'eliminar'], true)) {
            throw new DomainException('La acción no es válida.');
        }

        if ($accion !== 'crear' && ($idRecibido === false || $idRecibido < 1)) {
            throw new DomainException('Selecciona un estudiante válido.');
        }

        if ($accion === 'editar') {
            $id = $idRecibido;
        }

        if ($accion !== 'eliminar') {
            if ($nombre === '' || mb_strlen($nombre) > 100
                || strlen($correo) > 150 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                throw new DomainException('Escribe un nombre y un correo válidos.');
            }

            if (($accion === 'crear' || $clave !== '')
                && (mb_strlen($clave) < 8 || strlen($clave) > 72 || strpos($clave, "\0") !== false)) {
                throw new DomainException(
                    'Usa una contraseña de al menos 8 caracteres. Si es muy larga, acórtala.'
                );
            }
        }

        // La cuenta y el perfil se guardan o eliminan en una sola transacción.
        $db->beginTransaction();

        if ($accion === 'crear') {
            $consulta = $db->prepare(
                'INSERT INTO usuarios (nombre, correo, contrasena_hash, rol_id)
                 VALUES (?, ?, ?, (SELECT id FROM roles WHERE nombre = ?))'
            );
            $consulta->execute([
                $nombre, $correo, password_hash($clave, PASSWORD_DEFAULT), 'Estudiante',
            ]);

            $usuarioId = (int) $db->lastInsertId();
            $consulta = $db->prepare('INSERT INTO estudiantes (usuario_id) VALUES (?)');
            $consulta->execute([$usuarioId]);
            $mensaje = 'Estudiante agregado.';
        } else {
            $consulta = $db->prepare(
                'SELECT usuario_id FROM estudiantes WHERE id = ? FOR UPDATE'
            );
            $consulta->execute([$idRecibido]);
            $usuarioId = $consulta->fetchColumn();

            if ($usuarioId === false) {
                throw new DomainException('El estudiante ya no existe.');
            }

            if ($accion === 'editar') {
                $consulta = $db->prepare(
                    'UPDATE usuarios SET nombre = ?, correo = ? WHERE id = ?'
                );
                $consulta->execute([$nombre, $correo, $usuarioId]);

                if ($clave !== '') {
                    $consulta = $db->prepare(
                        'UPDATE usuarios SET contrasena_hash = ? WHERE id = ?'
                    );
                    $consulta->execute([password_hash($clave, PASSWORD_DEFAULT), $usuarioId]);
                }

                $mensaje = 'Cambios guardados.';
            } else {
                $consulta = $db->prepare('DELETE FROM estudiantes WHERE id = ?');
                $consulta->execute([$idRecibido]);
                $consulta = $db->prepare('DELETE FROM usuarios WHERE id = ?');
                $consulta->execute([$usuarioId]);
                $mensaje = 'Estudiante eliminado.';
            }
        }

        $db->commit();
        $_SESSION['aviso_estudiantes'] = $mensaje;
        header('Location: estudiantes.php', true, 303);
        exit;
    }

    $idEditar = filter_var($_GET['editar'] ?? '', FILTER_VALIDATE_INT);

    if ($idEditar > 0) {
        $consulta = $db->prepare(
            'SELECT e.id, u.nombre, u.correo
             FROM estudiantes e JOIN usuarios u ON u.id = e.usuario_id
             WHERE e.id = ?'
        );
        $consulta->execute([$idEditar]);
        $estudiante = $consulta->fetch();

        if (!$estudiante) {
            throw new DomainException('El estudiante ya no existe.');
        }

        $id = (int) $estudiante['id'];
        $nombre = $estudiante['nombre'];
        $correo = $estudiante['correo'];
    }
} catch (DomainException | PDOException $excepcion) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    if ($excepcion instanceof DomainException) {
        $error = $excepcion->getMessage();
    } else {
        $codigo = (int) ($excepcion->errorInfo[1] ?? 0);

        if ($codigo === 1062) {
            $error = 'Ese correo ya está registrado.';
        } elseif ($codigo === 1451) {
            $error = 'Este estudiante tiene matrículas asociadas.';
        } else {
            error_log($excepcion->getMessage());
            $error = 'No se pudo completar la operación.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estudiantes | Gestión Escolar</title>
    <style>
        /* Fondo celeste y rosado del sistema escolar. */
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
    <h1>Estudiantes</h1>

    <?php if ($error !== ''): ?>
        <p class="mensaje error" role="alert"><?= escapar($error) ?></p>
    <?php elseif ($aviso !== ''): ?>
        <p class="mensaje" role="status"><?= escapar($aviso) ?></p>
    <?php endif; ?>

    <form class="formulario" method="post" action="estudiantes.php">
        <h2><?= $id > 0 ? 'Editar estudiante' : 'Nuevo estudiante' ?></h2>

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

        <button type="submit"><?= $id > 0 ? 'Guardar cambios' : 'Agregar estudiante' ?></button>

        <?php if ($id > 0): ?>
            <a href="estudiantes.php">Cancelar edición</a>
        <?php endif; ?>
    </form>

    <h2>Estudiantes registrados</h2>

    <?php if ($estudiantes === [] && $error === ''): ?>
        <p>Todavía no hay estudiantes registrados.</p>
    <?php endif; ?>

    <ul>
        <?php foreach ($estudiantes as $estudiante): ?>
            <li>
                <strong><?= escapar($estudiante['nombre']) ?></strong>
                <p><?= escapar($estudiante['correo']) ?></p>

                <div class="acciones">
                    <a href="estudiantes.php?editar=<?= (int) $estudiante['id'] ?>">Editar</a>

                    <form class="form-eliminar" method="post" action="estudiantes.php">
                        <input type="hidden" name="token" value="<?= escapar($token) ?>">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id" value="<?= (int) $estudiante['id'] ?>">
                        <button type="submit">Eliminar</button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>

    <footer>Hecho por Norka Mideros</footer>
</main>

<script>
    // Confirma antes de eliminar la cuenta y el perfil del estudiante.
    document.querySelectorAll('.form-eliminar').forEach(function (formulario) {
        formulario.addEventListener('submit', function (evento) {
            if (!window.confirm('¿Quieres eliminar al estudiante y su cuenta?')) {
                evento.preventDefault();
            }
        });
    });
</script>
</body>
</html>