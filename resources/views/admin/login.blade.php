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
        <p class="login-intro">Sign in with your administrator account to manage MatchLearn.</p>

        @if ($errors->any())
            <div class="login-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.login.submit') }}" class="login-form">
            @csrf
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <label class="remember-row">
                <input type="checkbox" name="remember" value="1">
                <span>Keep me signed in</span>
            </label>
            <button class="button button-primary login-submit" type="submit">Sign in to admin</button>
        </form>
        <p class="login-footnote">Administrator access is managed by your system administrator.</p>
    </main>
</body>
</html>
