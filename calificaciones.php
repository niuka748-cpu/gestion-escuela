<?php
declare(strict_types=1);

session_start();

$usuario = $_SESSION['usuario'] ?? null;

if ($usuario === null) {
    header('Location: login.php');
    exit;
}

$rol = $usuario['rol'];

if (!in_array($rol, ['Administrador', 'Profesor', 'Estudiante'], true)) {
    http_response_code(403);
    exit('Tu perfil no tiene acceso a las calificaciones.');
}

require_once __DIR__ . '/conexion.php';

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

function textoEnviado(string $campo): string
{
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

function idPositivo($valor): int
{
    return filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]) ?: 0;
}

$puedeEditar = $rol !== 'Estudiante';
$volver = $rol === 'Administrador' ? 'panel.php' : 'login.php';

$_SESSION['token_calificaciones'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['token_calificaciones'];

$aviso = $_SESSION['aviso_calificaciones'] ?? '';
unset($_SESSION['aviso_calificaciones']);

$error = '';
$matriculas = [];
$calificaciones = [];
$idEditar = 0;

// Estas condiciones se aplican tanto a la lectura como a los cambios.
$permiso = "m.estado = 'Aprobada'";
$parametros = [];

if ($rol === 'Profesor') {
    $permiso .= ' AND p.usuario_id = ?';
    $parametros[] = (int) $usuario['id'];
} elseif ($rol === 'Estudiante') {
    $permiso .= ' AND e.usuario_id = ?';
    $parametros[] = (int) $usuario['id'];
}

$relaciones = '
    JOIN cursos c ON c.id = m.curso_id
    JOIN profesores p ON p.id = c.profesor_id
    JOIN estudiantes e ON e.id = m.estudiante_id
    JOIN usuarios u ON u.id = e.usuario_id
';

try {
    $db = conectarBaseDatos();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puedeEditar || !hash_equals($token, textoEnviado('token'))) {
            http_response_code(403);
            exit('La acción no está autorizada.');
        }

        $accion = textoEnviado('accion');
        $id = idPositivo($_POST['id'] ?? null);

        try {
            if ($id === 0 || !in_array($accion, ['crear', 'editar', 'eliminar'], true)) {
                throw new DomainException('Selecciona un registro válido.');
            }

            if ($accion === 'editar') {
                $idEditar = $id;
            }

            if ($accion !== 'eliminar') {
                $evaluacion = textoEnviado('evaluacion');
                $nota = str_replace(',', '.', textoEnviado('nota'));

                if ($evaluacion === '' || mb_strlen($evaluacion) > 100) {
                    throw new DomainException('Escribe una evaluación de hasta 100 caracteres.');
                }

                if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $nota) || (float) $nota > 100) {
                    throw new DomainException('Ingresa una nota de 0 a 100 con hasta dos decimales.');
                }
            }

            // Cada operación verifica el acceso en la misma consulta que modifica la nota.
            if ($accion === 'crear') {
                $consulta = $db->prepare(
                    "INSERT INTO calificaciones (matricula_id, evaluacion, nota)
                     SELECT m.id, ?, ? FROM matriculas m $relaciones
                     WHERE m.id = ? AND $permiso"
                );
                $consulta->execute([$evaluacion, $nota, $id, ...$parametros]);
                $mensaje = 'Calificación registrada.';
            } elseif ($accion === 'editar') {
                $consulta = $db->prepare(
                    "UPDATE calificaciones n
                     JOIN matriculas m ON m.id = n.matricula_id $relaciones
                     SET n.evaluacion = ?, n.nota = ?
                     WHERE n.id = ? AND $permiso"
                );
                $consulta->execute([$evaluacion, $nota, $id, ...$parametros]);
                $mensaje = 'Cambios guardados.';
            } else {
                $consulta = $db->prepare(
                    "DELETE n FROM calificaciones n
                     JOIN matriculas m ON m.id = n.matricula_id $relaciones
                     WHERE n.id = ? AND $permiso"
                );
                $consulta->execute([$id, ...$parametros]);
                $mensaje = 'Calificación eliminada.';
            }

            if ($consulta->rowCount() === 0) {
                if ($accion !== 'editar') {
                    throw new DomainException('El registro ya no está disponible para tu perfil.');
                }

                $consulta = $db->prepare(
                    "SELECT n.id FROM calificaciones n
                     JOIN matriculas m ON m.id = n.matricula_id $relaciones
                     WHERE n.id = ? AND $permiso"
                );
                $consulta->execute([$id, ...$parametros]);

                if ($consulta->fetchColumn() === false) {
                    throw new DomainException('El registro ya no está disponible para tu perfil.');
                }

                $mensaje = 'La calificación sigue con los mismos datos.';
            }

            $_SESSION['aviso_calificaciones'] = $mensaje;
            header('Location: calificaciones.php', true, 303);
            exit;
        } catch (DomainException $excepcion) {
            $error = $excepcion->getMessage();
        } catch (PDOException $excepcion) {
            if ((int) ($excepcion->errorInfo[1] ?? 0) !== 1062) {
                throw $excepcion;
            }

            $error = 'Ya existe una nota con ese nombre de evaluación en la matrícula.';
        }
    } else {
        $idEditar = idPositivo($_GET['editar'] ?? null);
    }

    $consulta = $db->prepare(
        "SELECT n.id, n.evaluacion, n.nota, c.nombre AS curso, u.nombre AS estudiante
         FROM calificaciones n
         JOIN matriculas m ON m.id = n.matricula_id $relaciones
         WHERE $permiso ORDER BY c.nombre, u.nombre, n.id DESC"
    );
    $consulta->execute($parametros);
    $calificaciones = $consulta->fetchAll();

    if ($puedeEditar) {
        $consulta = $db->prepare(
            "SELECT m.id, c.nombre AS curso, u.nombre AS estudiante
             FROM matriculas m $relaciones
             WHERE $permiso ORDER BY c.nombre, u.nombre"
        );
        $consulta->execute($parametros);
        $matriculas = $consulta->fetchAll();
    }
} catch (PDOException $excepcion) {
    error_log($excepcion->getMessage());
    $error = 'No se pudo completar la operación con las calificaciones.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calificaciones | Gestión Escolar</title>
    <style>
        /* Fondo celeste, formularios suaves y acciones rosadas. */
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

        input, select, button {
            min-width: 0;
            padding: 10px 12px;
            border-radius: 10px;
            font: inherit;
        }

        input, select { border: 1px solid #8bcfea; }

        button {
            border: 0;
            background: #ffc9df;
            color: #5b2941;
            font-weight: 700;
            cursor: pointer;
        }

        article {
            padding: 16px 0;
            border-bottom: 1px solid #f0d9e5;
            overflow-wrap: anywhere;
        }

        article h3 { margin: 0; }
        .acciones { display: flex; align-items: center; gap: 14px; }
        .mensaje { padding: 12px; border-radius: 10px; background: #e2f5ff; }
        .error { background: #ffe5f0; color: #742044; }
        footer { margin-top: 24px; text-align: center; color: #894368; }
    </style>
</head>
<body>
<main>
    <a href="<?= escapar($volver) ?>">← Volver</a>
    <h1><?= $puedeEditar ? 'Calificaciones' : 'Mis calificaciones' ?></h1>

    <?php if ($error !== ''): ?>
        <p class="mensaje error" role="alert"><?= escapar($error) ?></p>
    <?php elseif ($aviso !== ''): ?>
        <p class="mensaje" role="status"><?= escapar($aviso) ?></p>
    <?php endif; ?>

    <?php if ($matriculas !== []): ?>
        <form class="formulario" method="post" action="calificaciones.php">
            <input type="hidden" name="token" value="<?= escapar($token) ?>">
            <input type="hidden" name="accion" value="crear">

            <label for="matricula">Estudiante y curso</label>
            <select id="matricula" name="id" required>
                <option value="">Selecciona una matrícula aprobada</option>
                <?php foreach ($matriculas as $matricula): ?>
                    <option value="<?= (int) $matricula['id'] ?>">
                        <?= escapar($matricula['estudiante'] . ' · ' . $matricula['curso']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="evaluacion">Evaluación</label>
            <input id="evaluacion" name="evaluacion" maxlength="100" required>

            <label for="nota">Nota sobre 100</label>
            <input id="nota" name="nota" type="number" min="0" max="100" step="0.01" required>

            <button type="submit">Guardar calificación</button>
        </form>
    <?php elseif ($puedeEditar && $error === ''): ?>
        <p>Todavía no hay matrículas aprobadas para calificar.</p>
    <?php endif; ?>

    <h2>Notas registradas</h2>

    <?php if ($calificaciones === [] && $error === ''): ?>
        <p>Todavía no hay calificaciones.</p>
    <?php endif; ?>

    <?php foreach ($calificaciones as $registro): ?>
        <?php $id = (int) $registro['id']; ?>
        <article>
            <h3><?= escapar($registro['evaluacion']) ?></h3>
            <p><?= escapar($registro['curso']) ?>
                <?php if ($puedeEditar): ?>
                    · <?= escapar($registro['estudiante']) ?>
                <?php endif; ?>
            </p>
            <p><strong><?= escapar((string) $registro['nota']) ?> / 100</strong></p>

            <?php if ($puedeEditar && $idEditar === $id): ?>
                <form class="formulario" method="post" action="calificaciones.php">
                    <input type="hidden" name="token" value="<?= escapar($token) ?>">
                    <input type="hidden" name="accion" value="editar">
                    <input type="hidden" name="id" value="<?= $id ?>">

                    <label for="evaluacion-editar">Evaluación</label>
                    <input id="evaluacion-editar" name="evaluacion" maxlength="100"
                           value="<?= escapar($registro['evaluacion']) ?>" required>

                    <label for="nota-editar">Nota sobre 100</label>
                    <input id="nota-editar" name="nota" type="number" min="0" max="100" step="0.01"
                           value="<?= escapar((string) $registro['nota']) ?>" required>

                    <button type="submit">Guardar cambios</button>
                    <a href="calificaciones.php">Cancelar</a>
                </form>
            <?php elseif ($puedeEditar): ?>
                <div class="acciones">
                    <a href="calificaciones.php?editar=<?= $id ?>">Editar</a>
                    <form class="form-eliminar" method="post" action="calificaciones.php">
                        <input type="hidden" name="token" value="<?= escapar($token) ?>">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <button type="submit">Eliminar</button>
                    </form>
                </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>

    <footer>Hecho por Norka Mideros</footer>
</main>
<script>
    // Confirma antes de eliminar una nota.
    document.querySelectorAll('.form-eliminar').forEach(function (formulario) {
        formulario.addEventListener('submit', function (evento) {
            if (!window.confirm('¿Quieres eliminar esta calificación?')) {
                evento.preventDefault();
            }
        });
    });
</script>
</body>
</html>