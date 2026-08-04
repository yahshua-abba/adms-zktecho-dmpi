{{--
    Shared bottom-of-table row for Laravel-paginated tables: "Showing X to Y
    of Z entries" + the page links, mirroring the info/pagination row
    DataTables renders on its own for the AJAX-backed tables. Required:
    $paginator.
--}}
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
    <div class="small text-muted">
        @if ($paginator->total() > 0)
            Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }} entries
        @else
            No entries
        @endif
    </div>
    {{ $paginator->onEachSide(1)->links() }}
</div>
