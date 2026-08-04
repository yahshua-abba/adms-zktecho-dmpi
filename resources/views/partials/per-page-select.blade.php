{{--
    Shared "Show N entries" control for Laravel-paginated tables (Server
    Activity, Employees). DataTables-backed tables (Attendance, Devices,
    Device Check-ins/Messages) get the same options via their own JS
    `lengthMenu` instead — see App\Support\PerPage, the single source for the
    option list both sides read from.

    Required: $paginator, $param (query string key, e.g. "mapped_per_page"),
    $pageParam (this table's own page key, reset to page 1 on change).
    Optional: $extra (array of extra query params to force onto the URL, e.g.
    ['tab' => 'unmapped'] so switching page size doesn't jump tabs).
--}}
@php
    $extra = $extra ?? [];
@endphp
<div class="d-flex align-items-center gap-2 small text-muted">
    <label for="{{ $param }}" class="mb-0">Show</label>
    <select
        id="{{ $param }}"
        class="form-select form-select-sm w-auto"
        onchange="
            var url = new URL(window.location.href);
            url.searchParams.set('{{ $param }}', this.value);
            url.searchParams.delete('{{ $pageParam }}');
            @foreach ($extra as $key => $value)
                url.searchParams.set('{{ $key }}', '{{ $value }}');
            @endforeach
            window.location.href = url.toString();
        "
    >
        @foreach (\App\Support\PerPage::OPTIONS as $option)
            <option value="{{ $option }}" @selected($paginator->perPage() === $option)>{{ $option }}</option>
        @endforeach
    </select>
    <span>entries</span>
</div>
