<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $namaKonsumen }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; }
        .page {
            page-break-after: always;
            overflow: hidden;
            text-align: center;
            position: relative;
        }
        .page:last-child { page-break-after: avoid; }
        .page img { display: block; margin: 0 auto; }
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 80px;
            color: rgba(0, 0, 0, 0.08);
            font-weight: bold;
            white-space: nowrap;
            pointer-events: none;
        }
    </style>
</head>
<body>
    @foreach ($images as $img)
        <div class="page" style="width: {{ $img['width'] }}pt; height: {{ $img['height'] }}pt;">
            <div class="watermark">Menara Agung</div>
            <img src="data:image/jpeg;base64,{{ $img['base64'] }}" style="width: {{ $img['width'] }}pt; height: {{ $img['height'] }}pt;" alt="Foto {{ $loop->iteration }}">
        </div>
    @endforeach
</body>
</html>
