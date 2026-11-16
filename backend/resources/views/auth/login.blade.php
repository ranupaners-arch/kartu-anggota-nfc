<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body>
<div class="login card">
    <h1>Masuk Dashboard</h1>
    @if ($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif
    <form method="post" action="{{ route('login') }}">
        @csrf
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus style="width:100%">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required style="width:100%">
        <label style="font-weight:400"><input type="checkbox" name="remember" value="1"> Ingat saya</label>
        <p><button style="width:100%">Masuk</button></p>
    </form>
</div>
</body>
</html>
