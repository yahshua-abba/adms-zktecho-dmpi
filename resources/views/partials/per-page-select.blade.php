{{--
    Shared "Show N entries" control for Laravel-paginated tables (Server
    Activity, Employees, DMPI Calls). DataTables-backed tables (Attendance,
    Devices, Device Check-ins/Messages) get the same options via their own JS
    `lengthMenu` instead — see App\Support\PerPage, the single source for the
    option list both sides read from.

    Required: $paginator, $param (query string key, e.g. "mapped_per_page"),
    $pageParam (this table's own page key, reset to page 1 on change).
    Optional: $extra (array of extra query params to force onto the URL, e.g.
    ['tab' => 'unmapped'] so switching page size doesn't jump tabs).

    Deliberately NOT an inline onchange="". Inline handlers run inside an
    implicit `with (document)`, so the bare identifier `URL` resolves to
    `document.URL` — a *string* — instead of the URL constructor, and
    `new URL(location.href)` dies with "URL is not a constructor" before the
    handler can navigate. The select still visibly moved to the new value, so
    every page-size picker looked like it was ignoring the choice. A delegated
    listener runs in normal scope, where `URL` is the constructor.
--}}
@php
    $extra = $extra ?? [];
@endphp
<div class="d-flex align-items-center gap-2 small text-muted">
    <label for="{{ $param }}" class="mb-0">Show</label>
    <select
        id="{{ $param }}"
        class="form-select form-select-sm w-auto js-per-page"
        data-param="{{ $param }}"
        data-page-param="{{ $pageParam }}"
        data-extra="{{ json_encode($extra) }}"
    >
        @foreach (\App\Support\PerPage::OPTIONS as $option)
            <option value="{{ $option }}" @selected($paginator->perPage() === $option)>{{ $option }}</option>
        @endforeach
    </select>
    <span>entries</span>
</div>

@once
    @push('scripts')
        <script>
            // Delegated so one listener covers every picker on the page —
            // Employees renders three (Mapped / Unmapped PINs / PIN conflicts),
            // each driving its own page and page-size param.
            document.addEventListener('change', function (e) {
                var select = e.target.closest('.js-per-page');
                if (!select) {
                    return;
                }

                var url = new URL(window.location.href);
                url.searchParams.set(select.dataset.param, select.value);
                url.searchParams.delete(select.dataset.pageParam);

                var extra = JSON.parse(select.dataset.extra || '{}');
                Object.keys(extra).forEach(function (key) {
                    url.searchParams.set(key, extra[key]);
                });

                window.location.href = url.toString();
            });
        </script>
    @endpush
@endonce
