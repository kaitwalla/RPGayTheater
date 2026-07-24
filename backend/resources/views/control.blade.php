<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>RPGays Control</title>
        @include('partials.realtime-config')
        @vite('resources/control/main.ts')
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
