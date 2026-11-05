<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102a43">
    <title>Administrator sign in | {{ config('app.name', 'MatchLearn') }}</title>
    @vite(['resources/css/admin.css'])
</head>
<body class="admin-login-page">
    <main class="login-card">
        <a class="brand-lockup" href="{{ url('/') }}" aria-label="MatchLearn home">
            <span class="brand-mark">M</span>
            <span>Match<span class="brand-accent">Learn</span></span>
        </a>
        <p class="eyebrow">ADMINISTRATION</p>
        <h1>Welcome back</h1>
        <p class="login-intro">Use your administrator credentials to access the MatchLearn admin dashboard.</p>

        @if ($errors->any())
            <div class="login-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.login.submit') }}" class="login-form" id="admin-login-form">
            @csrf
            <label for="admin_username">Username</label>
            <input id="admin_username" name="username" type="text" value="{{ old('username', 'erial') }}" autocomplete="username" maxlength="120" required autofocus>

            <label for="admin_password">Password</label>
            <input id="admin_password" name="password" type="password" value="" autocomplete="current-password" required>

            <button class="button button-primary login-submit" type="submit">Sign in</button>
        </form>
        <p class="login-footnote">Administrator access is managed by your system administrator.</p>
    </main>
</body>
</html>
