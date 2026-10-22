<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f7fb">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin dashboard | {{ config('app.name', 'MatchLearn') }}</title>
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
</head>
<body class="admin-dashboard-page">
    <div id="admin-app"
        data-admin-name="{{ $admin->name }}"
        data-admin-email="{{ $admin->email }}"></div>
</body>
</html>
