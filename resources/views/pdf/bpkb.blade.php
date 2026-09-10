<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $namaKonsumen }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; padding: 0; }
        .page {
            page-break-after: always;
            overflow: hidden;
            text-align: center;
            position: relative;
        }
        .page:last-child { page-break-after: avoid; }
        .page img { display: block; margin: 0 auto; }
    </style>
</head>
<body>
    @foreach ($images as $img)
        <div class="page" style="width: {{ $img['width'] }}pt; height: {{ $img['height'] }}pt;">
            <img src="data:image/jpeg;base64,{{ $img['base64'] }}" style="width: {{ $img['width'] }}pt; height: {{ $img['height'] }}pt;" alt="Foto {{ $loop->iteration }}">
        </div>
    @endforeach
</body>
</html>
