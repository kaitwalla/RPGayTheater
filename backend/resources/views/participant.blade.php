<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#10131b">
        <link rel="manifest" href="/player.webmanifest">
        <link rel="apple-touch-icon" href="/player-icon.svg">
        <title>RPGays Player</title>
        @vite('resources/participant/main.ts')
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
