<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="app-base" content="{{ request()->getBaseUrl() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>LDA Sports Complex</title>
    @viteReactRefresh
    @vite(['resources/js/app.jsx'])
</head>
<body><div id="app"></div></body>
</html>
