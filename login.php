<?php
/**
 * Login Page — Authenticates users against the `users` table
 */
require_once __DIR__ . '/config.php';

// If already logged in, redirect to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';

// ---- Handle login form ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // CSRF protection — login is a POST like any other.
    $sessToken = (string)($_SESSION['csrf_token'] ?? '');
    $sentToken = (string)($_POST['csrf_token'] ?? '');

    if ($sessToken === '' || !hash_equals($sessToken, $sentToken)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Regenerate session ID to prevent fixation
            session_regenerate_id(true);

            $_SESSION['user_id']    = $user['id'];
            $_SESSION['full_name']  = $user['full_name'];
            $_SESSION['email']      = $user['email'];
            $_SESSION['role']       = $user['role'];

            // Update last_login timestamp
            $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);

            log_activity($pdo, 'login', 'Signed in successfully.');

            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
            log_activity($pdo, 'login_failed', 'Failed sign-in attempt for "' . $email . '".');
            // Small delay to slow brute-force attempts
            sleep(1);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Fuel Station Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        dark: { 900: '#0F172A', 800: '#1E293B', 700: '#334155', 600: '#475569' },
                        amber: { 400: '#FBBF24', 500: '#F59E0B' },
                        cyan:  { 400: '#22D3EE', 500: '#06B6D4' },
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .login-glow {
            box-shadow: 0 0 60px rgba(245,158,11,0.08), 0 0 120px rgba(6,182,212,0.05);
        }
        .input-focus:focus {
            box-shadow: 0 0 0 3px rgba(245,158,11,0.15);
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        .float-anim { animation: float 3s ease-in-out infinite; }
    </style>
</head>
<body class="bg-dark-900 text-gray-200 font-sans min-h-screen flex items-center justify-center relative overflow-hidden">

    <!-- Background Decorative Elements -->
    <div class="absolute top-0 left-0 w-96 h-96 bg-gradient-to-br from-amber-500/5 to-transparent rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2"></div>
    <div class="absolute bottom-0 right-0 w-96 h-96 bg-gradient-to-tl from-cyan-500/5 to-transparent rounded-full blur-3xl translate-x-1/2 translate-y-1/2"></div>
    <div class="absolute top-1/2 left-1/2 w-[600px] h-[600px] bg-gradient-to-r from-amber-500/3 to-cyan-500/3 rounded-full blur-3xl -translate-x-1/2 -translate-y-1/2"></div>

    <!-- Login Card -->
    <div class="login-glow relative z-10 w-full max-w-md mx-4">

        <!-- Brand Header -->
        <div class="text-center mb-8">
            <div class="float-anim w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-amber-500 to-cyan-500
                        flex items-center justify-center shadow-2xl shadow-amber-500/20 mb-5">
                <i class="fa-solid fa-gas-pump text-white text-3xl"></i>
            </div>
            <h1 class="text-white text-2xl font-bold">Fuel Station</h1>
            <p class="text-gray-500 text-sm mt-1">Management System</p>
        </div>

        <!-- Form Card -->
        <div class="bg-dark-800 border border-dark-700 rounded-2xl p-8 shadow-2xl">

            <div class="mb-6">
                <h2 class="text-white text-lg font-semibold">Welcome back</h2>
                <p class="text-gray-500 text-sm mt-1">Sign in to your account to continue</p>
            </div>

            <!-- Error Message -->
            <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-3 rounded-xl text-sm mb-5 flex items-center gap-2">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">
                <?= csrf_field() ?>
                <!-- Email -->
                <div>
                    <label class="text-gray-400 text-xs font-medium uppercase tracking-wider block mb-2">Email Address</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fa-solid fa-envelope text-gray-500 text-sm"></i>
                        </div>
                        <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" required autofocus
                               class="w-full bg-dark-700 border border-dark-600 rounded-xl pl-11 pr-4 py-3 text-white text-sm
                                      focus:outline-none focus:border-amber-500 input-focus transition placeholder-gray-600"
                               placeholder="admin@fuelstation.com">
                    </div>
                </div>

                <!-- Password -->
                <div>
                    <label class="text-gray-400 text-xs font-medium uppercase tracking-wider block mb-2">Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fa-solid fa-lock text-gray-500 text-sm"></i>
                        </div>
                        <input type="password" name="password" required id="password"
                               class="w-full bg-dark-700 border border-dark-600 rounded-xl pl-11 pr-12 py-3 text-white text-sm
                                      focus:outline-none focus:border-amber-500 input-focus transition placeholder-gray-600"
                               placeholder="Enter your password">
                        <button type="button" onclick="togglePass()"
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-500 hover:text-gray-300 transition">
                            <i class="fa-solid fa-eye text-sm" id="passIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember Me -->
                <div class="flex items-center justify-between">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="remember" class="w-4 h-4 rounded bg-dark-700 border-dark-600 text-amber-500 focus:ring-amber-500">
                        <span class="text-gray-400 text-sm">Remember me</span>
                    </label>
                </div>

                <!-- Submit -->
                <button type="submit"
                        class="w-full py-3 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 text-white font-semibold text-sm
                               hover:from-amber-400 hover:to-amber-500 transition-all duration-300 shadow-lg shadow-amber-500/20
                               hover:shadow-amber-500/30 active:scale-[0.98]">
                    <i class="fa-solid fa-right-to-bracket mr-2"></i> Sign In
                </button>
            </form>
        </div>

        <!-- Footer -->
        <p class="text-center text-gray-600 text-xs mt-6">
            &copy; <?= date('Y') ?> Fuel Station Management System
        </p>
    </div>

    <script>
        function togglePass() {
            const p = document.getElementById('password');
            const i = document.getElementById('passIcon');
            if (p.type === 'password') { p.type = 'text'; i.classList.replace('fa-eye','fa-eye-slash'); }
            else { p.type = 'password'; i.classList.replace('fa-eye-slash','fa-eye'); }
        }
    </script>
</body>
</html>
