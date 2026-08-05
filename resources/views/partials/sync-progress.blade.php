{{--
    Live strip for a running DMPI download.

    Hidden until the poll says something is running, so the page is unchanged when
    nothing is happening. The bar is deliberately indeterminate while we are waiting
    on DMPI: that stage is one blocking request, and inventing a percentage for it
    would be a lie that makes a hung call look like a progressing one.
--}}
<div id="sync-progress" class="card border-primary mb-4 d-none">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div>
                <span class="spinner-border spinner-border-sm text-primary me-2" role="status" aria-hidden="true"></span>
                <strong id="sync-what">Downloading…</strong>
                <span class="text-muted ms-2" id="sync-stage"></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-secondary" id="sync-elapsed">0s</span>
                <form method="POST" action="{{ route('dmpi.stop') }}"
                      onsubmit="return confirm('Stop this download? Anything already saved is kept; the rest is abandoned and you can start again.');">
                    @csrf
                    <button class="btn btn-outline-danger btn-sm"><i class="bi bi-stop-circle"></i> Stop</button>
                </form>
            </div>
        </div>

        <div class="progress" style="height: 1.25rem;" role="progressbar" aria-label="Download progress">
            <div id="sync-bar"
                 class="progress-bar progress-bar-striped progress-bar-animated"
                 style="width: 100%"></div>
        </div>
        <div class="small text-muted mt-1" id="sync-counts"></div>

        <div class="mt-3 d-none" id="sync-calls-wrap">
            <div class="small text-muted mb-1">Calls to DMPI</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0"><tbody id="sync-calls"></tbody></table>
            </div>
        </div>
    </div>
</div>

<script>
    // Inline scripts run before the deferred Vite bundle, so wait for the DOM.
    document.addEventListener('DOMContentLoaded', function () {
        var box = document.getElementById('sync-progress');
        if (!box) return;

        var what = document.getElementById('sync-what');
        var stage = document.getElementById('sync-stage');
        var elapsed = document.getElementById('sync-elapsed');
        var bar = document.getElementById('sync-bar');
        var counts = document.getElementById('sync-counts');
        var callsWrap = document.getElementById('sync-calls-wrap');
        var calls = document.getElementById('sync-calls');
        var wasRunning = false;

        function pretty(seconds) {
            if (seconds < 60) return seconds + 's';
            var m = Math.floor(seconds / 60), s = seconds % 60;
            return m + 'm ' + (s < 10 ? '0' : '') + s + 's';
        }

        function poll() {
            fetch(@json(route('dmpi.status')), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.running) {
                        box.classList.add('d-none');
                        // A download that finished while we watched: reload so the
                        // tables and Server Activity show the result.
                        if (wasRunning) { wasRunning = false; window.location.reload(); }
                        return;
                    }

                    wasRunning = true;
                    box.classList.remove('d-none');
                    what.textContent = d.what;
                    stage.textContent = d.stage || '';
                    elapsed.textContent = pretty(d.seconds);

                    if (d.percent === null || d.percent === undefined) {
                        // Indeterminate: full-width animated stripes, no number.
                        bar.style.width = '100%';
                        bar.classList.add('progress-bar-animated');
                        bar.textContent = '';
                        counts.textContent = 'Waiting on DMPI — no progress to report until it answers.';
                    } else {
                        bar.style.width = d.percent + '%';
                        bar.classList.remove('progress-bar-animated');
                        bar.textContent = d.percent + '%';
                        counts.textContent = (d.done || 0).toLocaleString() + ' of ' + (d.total || 0).toLocaleString();
                    }

                    if (d.calls && d.calls.length) {
                        callsWrap.classList.remove('d-none');
                        calls.innerHTML = d.calls.map(function (c) {
                            var badge = c.outcome === 'ok'
                                ? '<span class="badge bg-success">' + (c.status_code || 'ok') + '</span>'
                                : '<span class="badge bg-danger">' + (c.status_code || 'failed') + '</span>';
                            return '<tr><td><code>' + c.endpoint + '</code></td><td>' + badge +
                                '</td><td class="text-muted">' + c.duration + '</td><td class="text-muted">' + c.size +
                                '</td><td class="text-danger small">' + (c.error || '') + '</td></tr>';
                        }).join('');
                    }
                })
                .catch(function () { /* a blip in polling shouldn't break the page */ });
        }

        poll();
        setInterval(poll, 2000);
    });
</script>
