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
    exit('Solo el administrador puede gestionar cursos.');
}

require_once __DIR__ . '/conexion.php';

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

function enteroPositivo($valor): int
{
    return filter_var($valor, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]) ?: 0;
}

function fechaValida(string $valor): bool
{
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    return $fecha !== false && $fecha->format('Y-m-d') === $valor;
}

$_SESSION['token_cursos'] ??= bin2hex(random_bytes(32));
$token = $_SESSION['token_cursos'];
$aviso = $_SESSION['aviso_cursos'] ?? '';
unset($_SESSION['aviso_cursos']);

$error = '';
$asignaturas = [];
$profesores = [];
$cursos = [];
$curso = [
    'id' => 0,
    'nombre' => '',
    'descripcion' => '',
    'asignatura_id' => 0,
    'profesor_id' => 0,
    'fecha_inicio' => '',
    'fecha_fin' => '',
];

try {
    $db = conectarBaseDatos();

    // Carga las opciones directamente desde las tablas relacionadas.
    $asignaturas = $db->query('SELECT id, nombre FROM asignaturas ORDER BY nombre')->fetchAll();
    $profesores = $db->query(
        'SELECT p.id, u.nombre
         FROM profesores p JOIN usuarios u ON u.id = p.usuario_id
         ORDER BY u.nombre'
    )->fetchAll();

    $cursos = $db->query(
        'SELECT c.id, c.nombre, c.descripcion, c.fecha_inicio, c.fecha_fin,
                a.nombre AS asignatura, u.nombre AS profesor
         FROM cursos c
         JOIN asignaturas a ON a.id = c.asignatura_id
         JOIN profesores p ON p.id = c.profesor_id
         JOIN usuarios u ON u.id = p.usuario_id
         ORDER BY c.fecha_inicio DESC, c.nombre'
    )->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tokenRecibido = $_POST['token'] ?? '';

        if (!is_string($tokenRecibido) || !hash_equals($token, $tokenRecibido)) {
            http_response_code(403);
            exit('Vuelve a abrir la página de cursos e inténtalo otra vez.');
        }

        $accion = $_POST['accion'] ?? '';
        $id = enteroPositivo($_POST['id'] ?? null);

        if ($accion === 'eliminar' && $id > 0) {
            $consulta = $db->prepare('DELETE FROM cursos WHERE id = ?');
            $consulta->execute([$id]);
            $mensaje = $consulta->rowCount() ? 'Curso eliminado.' : 'El curso ya no existe.';
        } elseif ($accion === 'guardar') {
            // Conserva los valores del formulario si hay que corregir algún campo.
            foreach (['nombre', 'descripcion', 'fecha_inicio', 'fecha_fin'] as $campo) {
                $valor = $_POST[$campo] ?? '';
                $curso[$campo] = is_string($valor) ? trim($valor) : '';
            }

            $curso['id'] = $id;
            $curso['asignatura_id'] = enteroPositivo($_POST['asignatura_id'] ?? null);
            $curso['profesor_id'] = enteroPositivo($_POST['profesor_id'] ?? null);

            if ($curso['nombre'] === '' || mb_strlen($curso['nombre']) > 100
                || $curso['descripcion'] === '' || mb_strlen($curso['descripcion']) > 255
                || $curso['asignatura_id'] === 0 || $curso['profesor_id'] === 0) {
                throw new DomainException('Completa el nombre, la descripción y las dos selecciones.');
            }

            if (!fechaValida($curso['fecha_inicio']) || !fechaValida($curso['fecha_fin'])
                || $curso['fecha_fin'] < $curso['fecha_inicio']) {
                throw new DomainException('Revisa las fechas. El fin debe ser igual o posterior al inicio.');
            }

            $datos = [
                $curso['nombre'], $curso['descripcion'], $curso['asignatura_id'],
                $curso['profesor_id'], $curso['fecha_inicio'], $curso['fecha_fin'],
            ];

            if ($id > 0) {
                $consulta = $db->prepare(
                    'UPDATE cursos SET nombre = ?, descripcion = ?, asignatura_id = ?,
                     profesor_id = ?, fecha_inicio = ?, fecha_fin = ? WHERE id = ?'
                );
                $consulta->execute([...$datos, $id]);

                if ($consulta->rowCount() === 0) {
                    $existe = $db->prepare('SELECT id FROM cursos WHERE id = ?');
                    $existe->execute([$id]);

                    if ($existe->fetchColumn() === false) {
                        throw new DomainException('El curso ya no existe.');
                    }
                }

                $mensaje = 'Cambios guardados.';
            } else {
                $consulta = $db->prepare(
                    'INSERT INTO cursos (nombre, descripcion, asignatura_id, profesor_id,
                     fecha_inicio, fecha_fin) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $consulta->execute($datos);
                $mensaje = 'Curso agregado.';
            }
        } else {
            throw new DomainException('La acción solicitada no es válida.');
        }

        $_SESSION['aviso_cursos'] = $mensaje;
        header('Location: cursos.php', true, 303);
        exit;
    }

    $idEditar = enteroPositivo($_GET['editar'] ?? null);

    if ($idEditar > 0) {
        $consulta = $db->prepare(
            'SELECT id, nombre, descripcion, asignatura_id, profesor_id, fecha_inicio, fecha_fin
             FROM cursos WHERE id = ?'
        );
        $consulta->execute([$idEditar]);
        $registro = $consulta->fetch();

        if (!$registro) {
            throw new DomainException('El curso ya no existe.');
        }

        $curso = $registro;
    }
} catch (DomainException $excepcion) {
    $error = $excepcion->getMessage();
} catch (PDOException $excepcion) {
    $codigo = (int) ($excepcion->errorInfo[1] ?? 0);

    if ($codigo === 1451) {
        $error = 'Este curso tiene matrículas asociadas.';
    } elseif ($codigo === 1452) {
        $error = 'La asignatura o el profesor seleccionado ya no está disponible.';
    } else {
        error_log($excepcion->getMessage());
        $error = 'No se pudo completar la operación con los cursos.';
    }
}

$editando = (int) $curso['id'] > 0;
$puedeGuardar = $asignaturas !== [] && $profesores !== [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cursos | Gestión Escolar</title>
    <style>
        /* Colores del panel de Gestión Escolar. */
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
        input, textarea, select, button {
            min-width: 0;
            padding: 10px 12px;
            border-radius: 10px;
            font: inherit;
        }
        input, textarea, select { border: 1px solid #8bcfea; }
        button {
            border: 0;
            background: #ffc9df;
            color: #5b2941;
            font-weight: 700;
            cursor: pointer;
        }
        .mensaje { padding: 12px; border-radius: 10px; background: #e2f5ff; }
        .error { background: #ffe5f0; color: #742044; }
        .lista { list-style: none; padding: 0; }
        .lista li { padding: 16px 0; border-bottom: 1px solid #f0d9e5; }
        .acciones { display: flex; align-items: center; gap: 14px; }
        footer { margin-top: 24px; text-align: center; color: #894368; }
    </style>
</head>
<body>
<main>
    <a href="panel.php">← Volver al panel</a>
    <h1>Cursos</h1>

    <?php if ($error !== ''): ?>
        <p class="mensaje error" role="alert"><?= escapar($error) ?></p>
    <?php elseif ($aviso !== ''): ?>
        <p class="mensaje" role="status"><?= escapar($aviso) ?></p>
    <?php endif; ?>

    <?php if ($puedeGuardar): ?>
        <form class="formulario" method="post" action="cursos.php">
            <h2><?= $editando ? 'Editar curso' : 'Nuevo curso' ?></h2>
            <input type="hidden" name="token" value="<?= escapar($token) ?>">
            <input type="hidden" name="accion" value="guardar">
            <input type="hidden" name="id" value="<?= (int) $curso['id'] ?>">

            <label for="nombre">Nombre del curso</label>
            <input id="nombre" name="nombre" maxlength="100"
                   value="<?= escapar($curso['nombre']) ?>" required>

            <label for="descripcion">Descripción</label>
            <textarea id="descripcion" name="descripcion" rows="3"
                      maxlength="255" required><?= escapar($curso['descripcion']) ?></textarea>

            <label for="asignatura_id">Asignatura</label>
            <select id="asignatura_id" name="asignatura_id" required>
                <option value="">Selecciona una asignatura</option>
                <?php foreach ($asignaturas as $asignatura): ?>
                    <option value="<?= (int) $asignatura['id'] ?>"
                        <?= (int) $asignatura['id'] === (int) $curso['asignatura_id'] ? 'selected' : '' ?>>
                        <?= escapar($asignatura['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="profesor_id">Profesor</label>
            <select id="profesor_id" name="profesor_id" required>
                <option value="">Selecciona un profesor</option>
                <?php foreach ($profesores as $profesor): ?>
                    <option value="<?= (int) $profesor['id'] ?>"
                        <?= (int) $profesor['id'] === (int) $curso['profesor_id'] ? 'selected' : '' ?>>
                        <?= escapar($profesor['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="fecha_inicio">Fecha de inicio</label>
            <input id="fecha_inicio" name="fecha_inicio" type="date"
                   value="<?= escapar($curso['fecha_inicio']) ?>" required>

            <label for="fecha_fin">Fecha de fin</label>
            <input id="fecha_fin" name="fecha_fin" type="date"
                   value="<?= escapar($curso['fecha_fin']) ?>" required>

            <button type="submit"><?= $editando ? 'Guardar cambios' : 'Agregar curso' ?></button>
            <?php if ($editando): ?>
                <a href="cursos.php">Cancelar edición</a>
            <?php endif; ?>
        </form>
    <?php elseif ($error === ''): ?>
        <p>Para crear un curso, registra una <a href="asignaturas.php">asignatura</a>
           y un <a href="profesores.php">profesor</a>.</p>
    <?php endif; ?>

    <h2>Cursos registrados</h2>
    <?php if ($cursos === [] && $error === ''): ?>
        <p>Todavía no hay cursos registrados.</p>
    <?php endif; ?>

    <ul class="lista">
        <?php foreach ($cursos as $registro): ?>
            <li>
                <strong><?= escapar($registro['nombre']) ?></strong>
                <p><?= escapar($registro['descripcion']) ?></p>
                <p><?= escapar($registro['asignatura']) ?> · <?= escapar($registro['profesor']) ?></p>
                <p>Del <?= escapar($registro['fecha_inicio']) ?> al <?= escapar($registro['fecha_fin']) ?></p>
                <div class="acciones">
                    <a href="cursos.php?editar=<?= (int) $registro['id'] ?>">Editar</a>
                    <form class="form-eliminar" method="post" action="cursos.php">
                        <input type="hidden" name="token" value="<?= escapar($token) ?>">
                        <input type="hidden" name="accion" value="eliminar">
                        <input type="hidden" name="id" value="<?= (int) $registro['id'] ?>">
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
            if (!window.confirm('¿Quieres eliminar este curso?')) {
                evento.preventDefault();
            }
        });
    });
</script>
</body>
</html>