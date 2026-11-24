<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/conexion.php';

if (isset($_SESSION['usuario'])) {
    header('Location: panel.php');
    exit;
}

$error = '';
$correo = '';

// Habilita las cuentas de demostración en el entorno local.
$esLocal = in_array(
    $_SERVER['REMOTE_ADDR'] ?? '',
    ['127.0.0.1', '::1'],
    true
);

// Verifica el correo y la contraseña en MySQL.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim((string) ($_POST['correo'] ?? ''));
    $contrasena = (string) ($_POST['contrasena'] ?? '');

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || $contrasena === '') {
        $error = 'Ingresa un correo válido y tu contraseña.';
    } elseif (!$esLocal && in_array(strtolower($correo), [
        'admin@demo.test',
        'profesor@demo.test',
        'estudiante@demo.test'
    ], true)) {
        $error = 'Las cuentas de demostración están habilitadas solo en el entorno local.';
    } else {
        try {
            $consulta = conectarBaseDatos()->prepare(
                'SELECT u.id, u.nombre, u.contrasena_hash, r.nombre AS rol
                 FROM usuarios u
                 INNER JOIN roles r ON r.id = u.rol_id
                 WHERE u.correo = ?'
            );

            $consulta->execute([$correo]);
            $usuario = $consulta->fetch();

            if ($usuario && password_verify($contrasena, $usuario['contrasena_hash'])) {
                session_regenerate_id(true);

                $_SESSION['usuario'] = [
                    'id' => (int) $usuario['id'],
                    'nombre' => $usuario['nombre'],
                    'rol' => $usuario['rol']
                ];

                header('Location: panel.php', true, 303);
                exit;
            }

            $error = 'El correo o la contraseña son incorrectos.';

        } catch (PDOException $excepcion) {
            error_log($excepcion->getMessage());
            $error = 'No se pudo conectar con la base de datos escolar.';
        }
    }
}

function escapar(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso | Gestión Escolar</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            display: grid;
            place-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            background: linear-gradient(135deg, #d9f3ff, #fff0f6);
            color: #243850;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        main {
            width: min(100%, 500px);
            padding: 32px;
            background: white;
            border-top: 6px solid #8bcfea;
            border-radius: 24px;
            box-shadow: 0 18px 45px #294d6920;
        }

        .cabecera {
            text-align: center;
        }

        .icono {
            font-size: 48px;
        }

        h1 {
            margin: 10px 0;
        }

        .subtitulo {
            color: #64748b;
            margin-bottom: 25px;
        }

        form {
            display: grid;
            gap: 10px;
        }

        label {
            font-weight: 600;
        }

        input, button {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            font: inherit;
        }

        input {
            min-width: 0;
            border: 1px solid #8bcfea;
            background: #f4fbff;
        }

        button {
            border: none;
            background: #ffc9df;
            color: #5b2941;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }

        button:hover {
            background: #ffb5d2;
        }

        .demostracion {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #f0d9e5;
            text-align: center;
        }

        .demostracion h3 {
            margin: 0 0 8px;
        }

        .demostracion p {
            font-size: 14px;
            color: #64748b;
        }

        .demo {
            margin-top: 10px;
            background: #dff3fc;
            color: #243850;
        }

        .demo:hover {
            background: #b9e5f8;
        }

        .rosado {
            background: #ffe1ef;
        }

        .error {
            padding: 12px;
            border-radius: 10px;
            background: #ffe5f0;
            color: #742044;
        }

        footer {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid #f0d9e5;
            text-align: center;
            color: #894368;
        }
    </style>
</head>

<body>

<main>

    <div class="cabecera">
        <div class="icono">🏫</div>
        <h1>Gestión Escolar</h1>
        <p class="subtitulo">Bienvenido a nuestra plataforma educativa</p>
    </div>

    <?php if ($error !== ''): ?>
        <p class="error" role="alert"><?= escapar($error) ?></p>
    <?php endif; ?>

    <form id="formulario" method="post" action="login.php">

        <label for="correo">Correo electrónico</label>

        <input id="correo" name="correo" type="email"
               value="<?= escapar($correo) ?>"
               placeholder="Ingresa tu correo" required>

        <label for="contrasena">Contraseña</label>

        <input id="contrasena" name="contrasena" type="password"
               placeholder="Ingresa tu contraseña" required>

        <button type="submit">Ingresar</button>

    </form>

    <?php if ($esLocal): ?>

        <section class="demostracion">

            <h3>Acceso de demostración</h3>

            <p>Selecciona un perfil para explorar el sistema.</p>

            <button class="demo" type="button"
                    onclick="probar('admin@demo.test')">
                👩‍💼 Probar como administrador
            </button>

            <button class="demo rosado" type="button"
                    onclick="probar('profesor@demo.test')">
                👩‍🏫 Probar como profesor
            </button>

            <button class="demo" type="button"
                    onclick="probar('estudiante@demo.test')">
                🎓 Probar como estudiante
            </button>

        </section>

        <script>
            // Completa las credenciales ficticias de demostración.
            function probar(correo) {
                document.getElementById('correo').value = correo;
                document.getElementById('contrasena').value = 'EscolarDemo2026';
                document.getElementById('formulario').requestSubmit();
            }
        </script>

    <?php endif; ?>

    <footer>🌸 Hecho por Norka Mideros</footer>

</main>

</body>
</html>