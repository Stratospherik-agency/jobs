{{-- Thème « jobs » : feuille de style, réglages de lisibilité et icônes, tous servis par le site lui-même. --}}
@php
    $version = fn (string $file): int => @filemtime(public_path($file)) ?: 1;
@endphp
<meta name="theme-color" content="#EDF2F9" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#060C18" media="(prefers-color-scheme: dark)">
<link rel="apple-touch-icon" href="{{ asset('theme/apple-touch-icon.png') }}">
<link rel="preload" href="{{ asset('theme/fonts/inter-variable.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('theme/jobs.css') }}?v={{ $version('theme/jobs.css') }}">
<script src="{{ asset('theme/jobs.js') }}?v={{ $version('theme/jobs.js') }}"></script>
