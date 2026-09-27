<!doctype html>
<html lang="en" data-orbit-theme="{{ $page['props']['appearance'] }}" data-orbit-motion="{{ $page['props']['reduceMotion'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        const theme = document.documentElement.dataset.orbitTheme;
        const dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
        const motion = document.documentElement.dataset.orbitMotion;
        document.documentElement.classList.toggle('reduce-motion', motion === 'on' || (motion === 'system' && matchMedia('(prefers-reduced-motion: reduce)').matches));
    </script>
    @vite('resources/js/app.ts')
    <x-inertia::head />
</head>
<body>
    <x-inertia::app />
</body>
</html>
