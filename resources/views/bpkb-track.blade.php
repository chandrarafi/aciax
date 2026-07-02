<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tracking BPKB</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f1f5f9; color: #1e293b; min-height: 100vh; padding: 40px 16px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 32px; }
        .card { background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .info-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #64748b; }
        .info-value { font-weight: 500; }
        .badge { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
        .badge-queued { background: #f1f5f9; color: #64748b; }
        .badge-processing { background: #dbeafe; color: #2563eb; }
        .badge-completed { background: #dcfce7; color: #16a34a; }
        .badge-failed { background: #fee2e2; color: #dc2626; }
        .timeline { position: relative; padding-left: 40px; }
        .step { position: relative; padding-bottom: 24px; }
        .step:last-child { padding-bottom: 0; }
        .step-line { position: absolute; left: -25px; top: 28px; width: 2px; height: calc(100% - 12px); background: #e2e8f0; }
        .step.completed .step-line { background: #22c55e; }
        .step-icon { position: absolute; left: -40px; top: 0; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; }
        .step.pending .step-icon { background: #f1f5f9; color: #94a3b8; }
        .step.active .step-icon { background: #dbeafe; color: #2563eb; animation: pulse 1.5s infinite; }
        .step.completed .step-icon { background: #dcfce7; color: #16a34a; }
        .step.failed .step-icon { background: #fee2e2; color: #dc2626; }
        .step-label { font-weight: 600; font-size: 13px; }
        .step.pending .step-label { color: #94a3b8; }
        .step.active .step-label { color: #2563eb; }
        .step.completed .step-label { color: #16a34a; }
        .step.failed .step-label { color: #dc2626; }
        .step-desc { font-size: 12px; color: #94a3b8; margin-top: 1px; }
        .done-banner { display: flex; align-items: center; gap: 10px; padding: 12px; background: #dcfce7; border-radius: 8px; color: #16a34a; font-weight: 600; font-size: 13px; margin-top: 8px; }
        .fail-banner { padding: 12px; background: #fee2e2; border-radius: 8px; color: #dc2626; font-size: 13px; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 8px; font-weight: 600; font-size: 13px; text-decoration: none; border: none; cursor: pointer; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-primary:hover { background: #1d4ed8; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .download-btn { margin-top: 12px; text-align: center; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #e2e8f0; border-top-color: #2563eb; border-radius: 50%; animation: spin 0.8s linear infinite; margin: 0; }
        .header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        @keyframes spin { to { transform: rotate(360deg); } }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .empty { text-align: center; padding: 60px; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Tracking BPKB</h1>
        <p class="subtitle">Pantau proses pembuatan PDF BPKB secara realtime</p>

        @if($tracks->isEmpty())
            <div class="card"><div class="empty">Belum ada proses BPKB.</div></div>
        @endif

        @foreach($tracks as $track)
            <div class="card" data-track-id="{{ $track->id }}" data-status="{{ $track->status }}">
                <div class="header-row">
                    <div>
                        <strong>{{ $track->nama_konsumen ?? '-' }}</strong>
                        <span style="color:#94a3b8;font-size:13px;margin-left:8px">{{ $track->no_mesin }}</span>
                    </div>
                    <span class="badge badge-{{ $track->status }}" data-badge>{{ match($track->status) { 'queued' => 'Antrian', 'processing' => 'Diproses', 'completed' => 'Selesai', 'failed' => 'Gagal', default => $track->status } }}</span>
                </div>
                <div style="display:flex;gap:24px;font-size:13px;color:#64748b;margin-bottom:16px">
                    <span>BPKB: <b style="color:#1e293b">{{ $track->no_bpkb }}</b></span>
                    <span>ID: <code style="font-size:11px">{{ $track->id }}</code></span>
                    <span>{{ $track->created_at->format('d M Y H:i:s') }}</span>
                </div>

                <div class="timeline" data-timeline>
                    @php
                        $stages = [
                            ['key' => 'create_pdf', 'label' => 'Buat PDF', 'desc' => 'Kompresi gambar & generate PDF BPKB'],
                            ['key' => 'update_stok_unit', 'label' => 'Update Stok Unit', 'desc' => 'Simpan nomor BPKB ke stok unit'],
                            ['key' => 'update_tgl_bpkb_siap', 'label' => 'Update Tgl BPKB Siap', 'desc' => 'Set tanggal BPKB siap pada tanda terima'],
                        ];
                        $currentIdx = match($track->stage) {
                            'pending', 'failed' => -1,
                            'completed' => 3,
                            default => collect($stages)->search(fn($s) => $s['key'] === $track->stage) ?? -1,
                        };
                    @endphp
                    @foreach($stages as $i => $s)
                        @php
                            $done = $track->status === 'completed' || $i < $currentIdx;
                            $active = $track->status === 'processing' && $i === $currentIdx;
                            $fail = $track->status === 'failed' && $i === $currentIdx;
                            $state = $done ? 'completed' : ($active ? 'active' : ($fail ? 'failed' : 'pending'));
                        @endphp
                        <div class="step {{ $state }}">
                            @if($i < count($stages) - 1)<div class="step-line"></div>@endif
                            <div class="step-icon">
                                @if($done) &#10003;
                                @elseif($active) <span class="spinner"></span>
                                @elseif($fail) &#10007;
                                @else &#9675;
                                @endif
                            </div>
                            <div class="step-label">{{ $s['label'] }}</div>
                            <div class="step-desc">{{ $s['desc'] }}</div>
                            @if($fail && $track->error_message)
                                <div class="fail-banner" style="margin-top:6px">{{ $track->error_message }}</div>
                            @endif
                        </div>
                    @endforeach

                    @if($track->status === 'completed')
                        <div class="done-banner"><span>&#10003;</span> Selesai — PDF berhasil dibuat & data sudah diupdate</div>
                    @endif
                </div>

                @if($track->status === 'completed' && $track->pdf_path)
                    <div class="download-btn">
                        <a class="btn btn-primary btn-sm" href="/storage/{{ $track->pdf_path }}" target="_blank">&#8681; Download PDF</a>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <script>
        const STAGES = [
            { key: 'create_pdf', label: 'Buat PDF', desc: 'Kompresi gambar & generate PDF BPKB' },
            { key: 'update_stok_unit', label: 'Update Stok Unit', desc: 'Simpan nomor BPKB ke stok unit' },
            { key: 'update_tgl_bpkb_siap', label: 'Update Tgl BPKB Siap', desc: 'Set tanggal BPKB siap pada tanda terima' },
        ];
        const BADGE_HTML = {
            queued: '<span class="badge badge-queued">Antrian</span>',
            processing: '<span class="badge badge-processing">Diproses</span>',
            completed: '<span class="badge badge-completed">Selesai</span>',
            failed: '<span class="badge badge-failed">Gagal</span>',
        };
        const ICONS = {
            pending: '&#9675;',
            active: '<span class="spinner"></span>',
            completed: '&#10003;',
            failed: '&#10007;',
        };

        function getStageIndex(stage) {
            if (stage === 'pending' || stage === 'failed') return -1;
            if (stage === 'completed') return STAGES.length;
            return STAGES.findIndex(s => s.key === stage);
        }

        function renderTimeline(card, data) {
            const badge = card.querySelector('[data-badge]');
            if (badge) badge.outerHTML = BADGE_HTML[data.status] || '';

            const currentIdx = getStageIndex(data.stage);
            const isCompleted = data.status === 'completed';
            const isFailed = data.status === 'failed';
            let html = '';

            STAGES.forEach((s, i) => {
                const done = isCompleted || i < currentIdx;
                const active = data.status === 'processing' && i === currentIdx;
                const fail = isFailed && i === currentIdx;
                const state = done ? 'completed' : active ? 'active' : fail ? 'failed' : 'pending';

                html += '<div class="step ' + state + '">';
                if (i < STAGES.length - 1) html += '<div class="step-line"></div>';
                html += '<div class="step-icon">' + ICONS[state] + '</div>';
                html += '<div class="step-label">' + s.label + '</div>';
                html += '<div class="step-desc">' + s.desc + '</div>';
                if (fail && data.error_message) {
                    html += '<div class="fail-banner" style="margin-top:6px">' + data.error_message + '</div>';
                }
                html += '</div>';
            });

            if (isCompleted) {
                html += '<div class="done-banner"><span>&#10003;</span> Selesai — PDF berhasil dibuat & data sudah diupdate</div>';
            }

            card.querySelector('[data-timeline]').innerHTML = html;
            card.dataset.status = data.status;

            if (isCompleted && data.pdf_path && !card.querySelector('.download-btn')) {
                const div = document.createElement('div');
                div.className = 'download-btn';
                div.innerHTML = '<a class="btn btn-primary btn-sm" href="/storage/' + data.pdf_path + '" target="_blank">&#8681; Download PDF</a>';
                card.appendChild(div);
            }
        }

        async function pollAll() {
            const cards = document.querySelectorAll('[data-track-id]');
            for (const card of cards) {
                if (card.dataset.status === 'completed' || card.dataset.status === 'failed') continue;
                try {
                    const res = await fetch('/api/bpkb/track/' + card.dataset.trackId, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (res.ok) renderTimeline(card, await res.json());
                } catch {}
            }
        }

        setInterval(pollAll, 1500);
    </script>
</body>
</html>
