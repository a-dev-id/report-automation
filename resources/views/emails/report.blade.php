<!DOCTYPE html>
<html lang="{{ $property->language }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $property->name }}: {{ $report->type->title() }}</title>
</head>
<body style="margin:0; padding:24px; font-family:Arial, Helvetica, sans-serif; color:#222;">
    {{-- Placeholder layout. The real template (KPI tiles, tables, charts) is tracker phase 6. --}}
    <h1 style="font-size:20px; margin:0 0 4px;">{{ $property->name }}</h1>
    <p style="margin:0 0 16px; color:#666;">{{ $report->type->title() }}: {{ $period->label() }}</p>
    <p>The report content is added when the email template is built.</p>
</body>
</html>
