<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in — ADMS Server</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/js/app.js', 'resources/sass/app.scss'])
</head>
<body>
    <div class="login-shell d-flex align-items-center justify-content-center">
        <div class="login-card table-card">
            <div class="text-center mb-4">
                <span class="app-brand-static">
                    <i class="bi bi-fingerprint"></i>
                    <span>ADMS Server</span>
                </span>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}">
                @csrf
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" name="username" id="username" class="form-control" value="{{ old('username') }}" autofocus required>
                </div>
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" name="password" id="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Log in</button>
            </form>
        </div>
    </div>
</body>
</html>
