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
        <p class="login-intro">Enter your full name and continue with your authorized SSCT or SNSU administrator Google account.</p>

        @if ($errors->any())
            <div class="login-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.login.google') }}" class="login-form" id="admin-google-login">
            @csrf
            <label for="full_name">Full name</label>
            <input id="full_name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" maxlength="120" required autofocus>
            <input id="google_id_token" name="id_token" type="hidden">
            @if ($googleClientId)
                <div id="google-sign-in-button" class="admin-google-button" aria-label="Sign in with Google"></div>
                <p id="google-name-hint" class="login-footnote">Enter your full name to enable Google sign-in.</p>
            @else
                <p class="login-error" role="alert">Google sign-in is not configured on the server. Contact your administrator.</p>
            @endif
        </form>
        <p class="login-footnote">Administrator access is managed by your system administrator.</p>
    </main>
    @if ($googleClientId)
        <script>
            const adminLoginForm = document.getElementById('admin-google-login');
            const fullNameInput = document.getElementById('full_name');
            const googleButton = document.getElementById('google-sign-in-button');
            const googleNameHint = document.getElementById('google-name-hint');
            let googleApiReady = false;

            const updateGoogleButtonState = () => {
                const hasName = fullNameInput.value.trim().length >= 2;
                googleButton.classList.toggle('is-disabled', !hasName || !googleApiReady);
                googleButton.setAttribute('aria-disabled', String(!hasName || !googleApiReady));
                googleNameHint.hidden = hasName;
            };

            window.initializeGoogleSignIn = () => {
                googleApiReady = true;
                google.accounts.id.initialize({
                    client_id: @json($googleClientId),
                    callback: ({ credential }) => {
                        document.getElementById('google_id_token').value = credential;
                        adminLoginForm.submit();
                    },
                });
                google.accounts.id.renderButton(googleButton, {
                    type: 'standard',
                    theme: 'outline',
                    size: 'large',
                    text: 'signin_with',
                    shape: 'rectangular',
                    width: Math.min(360, Math.floor(googleButton.clientWidth)),
                });
                updateGoogleButtonState();
            };

            fullNameInput.addEventListener('input', updateGoogleButtonState);
            googleButton.addEventListener('click', (event) => {
                if (googleButton.getAttribute('aria-disabled') === 'true') {
                    event.preventDefault();
                    event.stopPropagation();
                }
            }, true);
        </script>
        <script src="https://accounts.google.com/gsi/client" async onload="initializeGoogleSignIn()"></script>
    @endif
</body>
</html>
